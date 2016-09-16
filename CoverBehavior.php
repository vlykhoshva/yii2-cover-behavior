<?php
/**
 * Created by PhpStorm.
 * User: vlad
 * Date: 13.09.16
 * Time: 12:17
 */

namespace Yii2CoverBehavior;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\web\UploadedFile;

class ImageBehavior extends Behavior
{
    public $image = null;
    public $modelAttribute = 'image';
    public $tableAttribute = 'image';
    public $path;

    public function init()
    {
        parent::init();

        if (empty($this->path)) {
            $this->path = Yii::getAlias('@frontend/web/uploads');
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
                $file = $this->path . $owner->$table_attribute;

                if (file_exists($file)) {
                    unlink($file);
                }
            }

            $owner->$table_attribute = uniqid() . '.' . $this->image->extension;
            $this->image->saveAs($this->path . $owner->$table_attribute);

            $this->image = '';
        }

        return true;
    }

    public function deleteImage()
    {
        $table_attribute = $this->tableAttribute;
        $file_path = $this->path . $this->owner->$table_attribute;
        unlink($file_path);
    }
}
