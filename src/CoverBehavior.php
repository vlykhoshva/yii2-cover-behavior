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
use yii\helpers\FileHelper;
use yii\helpers\VarDumper;
use yii\web\UploadedFile;

/**
 *
 * @property string $fileName generated filename
 * @property string $modelFullFileName
 * @property string $filePath
 * @property string $fileExtension
 */
class CoverBehavior extends Behavior
{
    const THUMBNAIL_INSET = ManipulatorInterface::THUMBNAIL_INSET;
    const THUMBNAIL_OUTBOUND = ManipulatorInterface::THUMBNAIL_OUTBOUND;

    /** @var string real existing model attribute that contain image name */
    public $modelAttribute;

    /** @var string specify model attribute where file path will be stored.
     * It need when $path attribute configured as callable function.
     * If $path == callable and this attribute empty, path will be stored as prefix in $modelAttribute */
    public $modelAttributeFilePath = null;

    /** @var string virtual attribute that will be placed in owner model object */
    public $relationAttribute = 'image';

    /** @var array options to generate thumbnails for incoming image */
    public $thumbnails = array();

    /**
     * @var string|callable path to store file. Default value use `'@frontend/web/uploads'`.
     * Callback function should has next template:
     * function($ownerActiveRecord) {
     *      return [string];
     * }
     */
    public $path;

    /** @var string path to watermark image file. Default NULL */
    public $watermark = null;

    /** @var callable Callback function to generate file name */
    public $fileNameGenerator;

    private $_relationAttributeValue, $_fileName, $_filePath;
    private $fileNameRegexp = '/[a-zA-Z0-9\-_]*\.\w{3,4}$/i';

    /** @inheritdoc */
    public function init()
    {
        parent::init();

        if (empty($this->path)) {
            $this->path = Yii::getAlias('@frontend/web/uploads');
        }
        if (empty($this->fileNameGenerator)) {
            $this->fileNameGenerator = function () {
                return uniqid();
            };
        } elseif (!is_callable($this->fileNameGenerator)) {
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
            ActiveRecord::EVENT_BEFORE_UPDATE => 'updatePathAndSaveImage',
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
            $isSaved = FileHelper::createDirectory($this->filePath);
            $isSaved = $isSaved && $this->_relationAttributeValue->saveAs(
                    $this->filePath . $this->fileName . '.' . $this->fileExtension);

            if (!$isSaved) {
                throw new Exception($this->_relationAttributeValue->name . ' not saved.');
            }

            $this->setModelFullFileName($this->filePath, $this->fileName . '.' . $this->fileExtension);
            $this->_relationAttributeValue = null;

            $this->addWatermark($owner->$table_attribute);
            $this->generateThumbnail($owner->$table_attribute);
        }
        return true;
    }

    public function updatePathAndSaveImage()
    {
        if (!empty($this->_relationAttributeValue) && $this->_relationAttributeValue instanceof UploadedFile) {
            $this->deleteImage();
            return $this->saveImage();
        }

        $old = $this->modelFullFileName;
        $newFullFileName = $this->filePath . $this->fileName . '.' . $this->fileExtension;
        if (is_callable($this->path) && $old !== $newFullFileName) {
            if ($this->modelAttributeFilePath) {
                $oldFilePath = $this->owner->{$this->modelAttributeFilePath};
            } else {
                $oldFilePath = preg_replace($this->fileNameRegexp, '', $this->owner->{$this->modelAttribute});
            }
            $isMoved = FileHelper::createDirectory($this->filePath);

            if ($isMoved = $isMoved && rename($old, $newFullFileName)) {
                $this->setModelFullFileName($this->filePath, $this->fileName . '.' . $this->fileExtension);
            }
            if (self::isDirectoryEmpty($oldFilePath)) {
                FileHelper::removeDirectory($oldFilePath);
            };
            return $isMoved;
        }
        return true;
    }

    public function deleteImage() // TODO check
    {
        if (!empty($this->modelAttributeFilePath)) {
            $file_name = $this->owner->{$this->modelAttribute};
        } elseif (!empty($this->owner->{$this->modelAttribute})) {
            preg_match($this->fileNameRegexp, $this->owner->{$this->modelAttribute}, $file_name);
            $file_name = $file_name[0];
        }
        if (file_exists($this->modelFullFileName)) {
            unlink($this->modelFullFileName);
            $this->deleteThumbnails($file_name);
        }
    }

    /**
     * Unlink all thumbnails of specified file
     * @param $originalFileName String Original file name
     */
    protected function deleteThumbnails($originalFileName)
    {
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
            $imagine = $imagine->open($this->filePath . $fileName);
            foreach ($this->thumbnails as $thumbnail) {
                $imagine->thumbnail(new Box($thumbnail['width'], $thumbnail['height']), $thumbnail['mode'])
                    ->save($this->filePath . $thumbnail['prefix'] . $fileName);
            }
        }
    }

    protected function getModelFullFileName()
    {
        if (empty($this->modelAttributeFilePath)) {
            return $this->owner->{$this->modelAttribute};
        } else {
            return $this->owner->{$this->modelAttributeFilePath} . $this->owner->{$this->modelAttribute};
        }
    }

    protected function setModelFullFileName($filePath, $fileName)
    {
        if ($this->modelAttributeFilePath) {
            $this->owner->{$this->modelAttributeFilePath} = $filePath;
            $this->owner->{$this->modelAttribute} = $fileName;
        } else {
            $this->owner->{$this->modelAttribute} = $filePath . $fileName;
        }
    }

    protected function getFileExtension()
    {
        if ($this->_relationAttributeValue instanceof UploadedFile) {
            return $this->_relationAttributeValue->extension;
        } elseif (!empty($this->owner->{$this->modelAttribute})) {
            $modelFile = $this->owner->{$this->modelAttribute};
            return substr($modelFile, 1 + strrpos($modelFile, '.', -1));
        }
        throw new Exception("$this->relationAttribute and \"$this->modelAttribute\" hasn't uploaded files.");
    }

    protected function getFilePath()
    {
        if (empty($this->_filePath)) {
            $this->_filePath = $this->getParamValue($this->path);
        }
        return $this->_filePath;
    }

    protected function getFileName()
    {
        if (empty($this->_fileName)) {
            $this->_fileName = $this->getParamValue($this->fileNameGenerator);
        }
        return $this->_fileName;
    }

    /**
     * @param callable|String $paramValue configured param value
     * @return string generated value
     * @throws InvalidConfigException if callback function didn't return String
     */
    protected function getParamValue($paramValue)
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

    protected static function isDirectoryEmpty($dir)
    {
        if (!is_readable($dir)) return null;
        $handle = opendir($dir);
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                return false;
            }
        }
        return true;
    }
}
