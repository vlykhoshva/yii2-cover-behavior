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
use Yii;
use yii\base\Behavior;
use yii\base\InvalidParamException;
use yii\base\UnknownPropertyException;
use yii\db\ActiveRecord;
use yii\web\UploadedFile;

class CoverBehavior extends Behavior
{
    const THUMBNAIL_INSET = ManipulatorInterface::THUMBNAIL_INSET;
    const THUMBNAIL_OUTBOUND = ManipulatorInterface::THUMBNAIL_OUTBOUND;

    public $modelAttribute;
    public $relationReferenceAttribute = 'image';
    public $thumbnails = array();
    public $path;

    private $_relationReferenceAttributeValue;

    public function init()
    {
        parent::init();

        if (empty($this->path)) {
            $this->path = Yii::getAlias('@frontend/web/uploads');
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
                    [ManipulatorInterface::THUMBNAIL_INSET, ManipulatorInterface::THUMBNAIL_OUTBOUND])) {
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
        } catch (UnknownPropertyException $exception) {
            if ($name === $this->relationReferenceAttribute) {
                if (is_null($this->_relationReferenceAttributeValue)) {
                    $this->_relationReferenceAttributeValue = $this->owner->{$this->modelAttribute};
                }
                return $this->_relationReferenceAttributeValue;
            }
            throw $exception;
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
        } catch (UnknownPropertyException $exception) {
            if ($name === $this->relationReferenceAttribute) {
                $this->_relationReferenceAttributeValue = $value;
            } else {
                throw $exception;
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function canGetProperty($name, $checkVars = true)
    {
        if (parent::canGetProperty($name, $checkVars)) {
            return true;
        }
        return ($name === $this->relationReferenceAttribute);
    }

    /**
     * @inheritdoc
     */
    public function canSetProperty($name, $checkVars = true)
    {
        if (parent::canSetProperty($name, $checkVars)) {
            return true;
        }
        return ($name === $this->relationReferenceAttribute);
    }

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
        $this->_relationReferenceAttributeValue = UploadedFile::getInstance($this->owner, $this->relationReferenceAttribute);
        return true;
    }

    public function saveImage()
    {
        $owner = $this->owner;
        $table_attribute = $this->modelAttribute;
        if ($this->_relationReferenceAttributeValue instanceof UploadedFile) {

            if ($owner->$table_attribute) {
                $this->deleteImage();
            }

            $owner->$table_attribute = uniqid() . '.' . $this->_relationReferenceAttributeValue->extension;
            $this->_relationReferenceAttributeValue->saveAs($this->path . $owner->$table_attribute);

            if (!empty($this->thumbnails)) {
                $imagine = new Imagine();
                $imagine = $imagine->open($this->path . $owner->$table_attribute);
                foreach ($this->thumbnails as $thumbnail) {
                    $imagine->thumbnail(new Box($thumbnail['width'], $thumbnail['height']), $thumbnail['mode'])
                        ->save($this->path . $thumbnail['prefix'] . $owner->$table_attribute);
                }
            }
            $this->_relationReferenceAttributeValue = '';
        }

        return true;
    }

    public function deleteImage()
    {
        $table_attribute = $this->modelAttribute;
        $file_name = $this->owner->$table_attribute;
        $file_path = $this->path . $file_name;
        if ((!empty($file_name)) && (file_exists($file_path))) {
            unlink($file_path);

            foreach ($this->thumbnails as $thumbnail) {
                $file_path = $this->path . $thumbnail['prefix'] . $this->owner->$table_attribute;
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
        }
    }
}
