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
use yii\db\ActiveRecord;
use yii\web\UploadedFile;

class CoverBehavior extends Behavior
{
    const THUMBNAIL_INSET = ManipulatorInterface::THUMBNAIL_INSET;
    const THUMBNAIL_OUTBOUND = ManipulatorInterface::THUMBNAIL_OUTBOUND;

    public $image = null;
    public $modelAttribute = 'image';
    public $tableAttribute = 'image';
    public $thumbnails = array();
    public $path;

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

    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'loadImage',
            ActiveRecord::EVENT_BEFORE_INSERT => 'saveImage',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'saveImage',
            ActiveRecord::EVENT_AFTER_DELETE => 'deleteImage',
            ActiveRecord::EVENT_AFTER_FIND => 'setImagePath',
        ];
    }

    public function setImagePath()
    {
        $owner = $this->owner;
        $table_attribute = $this->tableAttribute;
        $this->image = '/' . $this->path . $owner->$table_attribute;
    }

    public function loadImage()
    {
        $this->image = UploadedFile::getInstance($this->owner, 'image');
        return true;
    }

    public function saveImage()
    {
        $owner = $this->owner;
        $table_attribute = $this->tableAttribute;
        if ($this->image) {

            if ($owner->$table_attribute) {
                $this->deleteImage();
            }

            $owner->$table_attribute = uniqid() . '.' . $this->image->extension;
            $this->image->saveAs($this->path . $owner->$table_attribute);

            if (!empty($this->thumbnails)) {
                $imagine = new Imagine();
                $imagine = $imagine->open($this->path . $owner->$table_attribute);
                foreach ($this->thumbnails as $thumbnail) {
                    $imagine->thumbnail(new Box($thumbnail['width'], $thumbnail['height']), $thumbnail['mode'])
                        ->save($this->path . $thumbnail['prefix'] . $owner->$table_attribute);
                }
            }
            $this->image = '';
        }

        return true;
    }

    public function deleteImage()
    {
        $table_attribute = $this->tableAttribute;
        $file_path = $this->path . $this->owner->$table_attribute;
        if (file_exists($file_path)) {
            unlink($file_path);
        }

        foreach ($this->thumbnails as $thumbnail) {
            $file_path = $this->path . $thumbnail['prefix'] . $this->owner->$table_attribute;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
    }
}
