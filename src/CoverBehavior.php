<?php
/**
 * Created by PhpStorm.
 * User: lyhoshva
 * Date: 13.09.16
 * Time: 12:17
 */

namespace lyhoshva\Cover;

use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ManipulatorInterface;
use Imagine\Image\Point;
use Yii;
use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\base\UnknownPropertyException;
use yii\db\ActiveRecord;
use yii\helpers\VarDumper;
use yii\web\UploadedFile;

/**
 *
 * @property string $fileName generated filename
 * @property string $filePath
 */
class CoverBehavior extends Behavior
{
    const THUMBNAIL_INSET = ManipulatorInterface::THUMBNAIL_INSET;
    const THUMBNAIL_OUTBOUND = ManipulatorInterface::THUMBNAIL_OUTBOUND;

    /** @var  string real existing model attribute that contain image name */
    public $modelAttribute;

    /** @var string virtual attribute that will be placed in owner model object */
    public $relationAttribute = 'image';

    /** @var array options to generate thumbnails for incoming image */
    public $thumbnails = array();

    /**
     * @var  string|callable path to store file. Default value use `'@frontend/web/uploads'`.
     * Callback function should has next template:
     * function($ownerActiveRecord) {
     *      return [string];
     * }
     */
    public $path;

    /** @var string path to watermark image file. Default NULL */
    public $watermark = null;

    /** @var  callable Callback function to generate file name */
    public $fileNameGenerator;

    private $_relationAttributeValue;

    /** @inheritdoc */
    public function init()
    {
        parent::init();

        if (empty($this->path)) {
            $this->path = Yii::getAlias('@frontend/web/uploads');
        }
        if(empty($this->fileNameGenerator)) {
            $this->fileNameGenerator = function () {
                return uniqid();
            };
        } elseif(!is_callable($this->fileNameGenerator)) {
            throw new InvalidParamException('$fileNameGenerator should be callback function');
        }
        if (!empty($this->thumbnails)) {
            foreach ($this->thumbnails as &$thumbnail) {
                if (empty($thumbnail['prefix'])) {
                    throw new InvalidParamException('$thumbnails[\'prefix\'] can not be empty');
                }
                if (empty($thumbnail['width'])) {
                    throw new InvalidParamException('$thumbnails[\'width\'] have to be not empty');
                }
                if (empty($thumbnail['height'])) {
                    $thumbnail['height'] = $thumbnail['width'];
                }
                if (!is_numeric($thumbnail['width']) || !is_numeric($thumbnail['height'])) {
                    throw new InvalidParamException('$thumbnails[\'width\'] and $thumbnails[\'height\'] have to be a number');
                }
                if (empty($thumbnail['mode'])) {
                    $thumbnail['mode'] = ManipulatorInterface::THUMBNAIL_INSET;
                } elseif (!in_array($thumbnail['mode'],
                    [ManipulatorInterface::THUMBNAIL_INSET, ManipulatorInterface::THUMBNAIL_OUTBOUND])
                ) {
                    throw new InvalidParamException('Undefined mode in $thumbnail[\'mode\']');
                }
            }
        }
    }

    /**
     * PHP getter magic method.
     * This method is overridden so that relation attribute can be accessed like property.
     *
     * @param string $name property name
     * @throws UnknownPropertyException if the property is not defined
     * @return mixed property value
     */
    public function __get($name)
    {
        try {
            return parent::__get($name);
        } catch (UnknownPropertyException $e) {
            if ($name === $this->relationAttribute) {
                if (is_null($this->_relationAttributeValue)) {
                    $this->_relationAttributeValue = $this->owner->{$this->modelAttribute};
                }
                return $this->_relationAttributeValue;
            }
            throw $e;
        }
    }

    /**
     * PHP setter magic method.
     * This method is overridden so that relation attribute can be accessed like property.
     * @param string $name property name
     * @param mixed $value property value
     * @throws UnknownPropertyException if the property is not defined
     */
    public function __set($name, $value)
    {
        try {
            parent::__set($name, $value);
        } catch (UnknownPropertyException $e) {
            if ($name === $this->relationAttribute) {
                $this->_relationAttributeValue = $value;
            } else {
                throw $e;
            }
        }
    }

    /** @inheritdoc */
    public function canGetProperty($name, $checkVars = true)
    {
        return parent::canGetProperty($name, $checkVars) || $name === $this->relationAttribute;
    }

    /** @inheritdoc */
    public function canSetProperty($name, $checkVars = true)
    {
        return parent::canSetProperty($name, $checkVars) || $name === $this->relationAttribute;
    }

    /** @inheritdoc */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'loadImage',
            ActiveRecord::EVENT_BEFORE_INSERT => 'saveImage',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'saveImage',
            ActiveRecord::EVENT_AFTER_DELETE => 'deleteImage',
        ];
    }

    public function loadImage()
    {
        $this->_relationAttributeValue = UploadedFile::getInstance($this->owner, $this->relationAttribute);
        return true;
    }

    public function saveImage()
    {
        $owner = $this->owner;
        $table_attribute = $this->modelAttribute;
        if ($this->_relationAttributeValue instanceof UploadedFile) {
            if (!empty($owner->$table_attribute)) {
                $this->deleteImage();
            }
            $owner->$table_attribute = $this->fileName . '.' . $this->_relationAttributeValue->extension;
            $isSaved = $this->_relationAttributeValue->saveAs($this->path . $owner->$table_attribute);

            $this->addWatermark($owner->$table_attribute);
            $this->generateThumbnail($owner->$table_attribute);

            if($isSaved) {
                unlink($this->_relationAttributeValue);
                unlink($owner->$table_attribute);
            } else {
                throw new Exception($this->_relationAttributeValue->name . ' not saved.');
            }
        }
        return true;
    }

    public function deleteImage()
    {
        $file_name = $this->owner->{$this->modelAttribute};
        $file_path = $this->filePath . $file_name;
        if ((!empty($file_name)) && (file_exists($file_path))) {
            unlink($file_path);
            $this->deleteThumbnails($file_name);
        }
    }

    /**
     * Unlink all thumbnails of specified file
     * @param $originalFileName String Original file name
     */
    protected function deleteThumbnails($originalFileName) {
        foreach ($this->thumbnails as $thumbnail) {
            $file_path = $this->filePath . $thumbnail['prefix'] . $originalFileName;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
    }

    /**
     * Create watermark on uploaded image that stored in $watermark attribute if it not empty
     * @param $path String path to saved image
     */
    protected function addWatermark($path)
    {
        if (!empty($this->watermark)) {
            $imagine = new Imagine();
            $watermark = $imagine->open($this->watermark);
            $image = $imagine->open($path);
            $image_size = $image->getSize();
            $watermark = $watermark->crop(new Point(0, 0), new Box($image_size->getWidth(), $image_size->getHeight()));
            $image = $image->paste($watermark, new Point(0, 0));
            $image->save();
        }
    }

    /**
     * Generate thumbnail according configures in $thumbnails attribute. If it empty - thumbnails will not configure.
     * @param $fileName String file name
     */
    protected function generateThumbnail($fileName)
    {
        if (!empty($this->thumbnails)) {
            $imagine = new Imagine();
            $imagine = $imagine->open($this->path . $fileName);
            foreach ($this->thumbnails as $thumbnail) {
                $imagine->thumbnail(new Box($thumbnail['width'], $thumbnail['height']), $thumbnail['mode'])
                    ->save($this->path . $thumbnail['prefix'] . $fileName);
            }
        }
    }

    protected function getFilePath()
    {
        return $this->getParamValue($this->path);
    }

    protected function getFileName()
    {
        return $this->getParamValue($this->fileNameGenerator);
    }

    /**
     * @param callable|String $paramValue configured param value
     * @return string generated value
     * @throws InvalidConfigException if callback function didn't return String
     */
    private function getParamValue($paramValue)
    {
        if (is_callable($paramValue)) {
            $result = call_user_func($paramValue, $this->owner);
            if (!is_string($result)) {
                throw new InvalidConfigException('Callback function should return a String value. Result is '
                    . VarDumper::dumpAsString($result) . ' for '
                    . VarDumper::dumpAsString($paramValue));
            }
            return $result;
        }
        return $paramValue;
    }
}
