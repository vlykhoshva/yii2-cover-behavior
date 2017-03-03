# yii2-cover-behavior
Yii 2 behavior for image uploading

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
composer require --prefer-dist lyhoshva/yii2-cover-behavior
```

or add

```json
"lyhoshva/yii2-cover-behavior": "*"
```

to the require section of your composer.json.


Basic Usage
-----

This extension provides support for ActiveRecord image uploading.
This support is granted via [[\lyhoshva\Cover\CoverBehavior]] ActiveRecord behavior. You'll need to attach
it to your ActiveRecord class and point next options for it:

```php
class Article extends ActiveRecord
{
    public function behaviors()
    {
        return [
            'image' => [
                'class' => CoverBehavior::className(),
                'modelAttribute' => 'image_name', // attribute, which will contains image_name
                // virtual attribute, which is used for image uploading ("image" by default)
                'relationReferenceAttribute' => 'image', 
                'path' => 'uploads/articles/', // path to upload directory 
            ],
        ];
    }

    public function rules()
    {
        return [
            [['image'], 'required', 'on' => 'create'],
            [['image', 'cover_image'], 'file', 'extensions' => ['png', 'jpg', 'jpeg', 'gif']],
        ];
    }
}
```

> Attention: do NOT declare `relationReferenceAttribute` attribute in the owner ActiveRecord class. Make sure it does
  not conflict with any existing owner field or virtual property.

Virtual property declared via `relationReferenceAttribute` serves not only for saving. It also contains existing references
for the uploaded image

Advanced usage
-------------

Behavior can generate **path** (`'path'`) or **image name** (`'fileNameGenerator'`) automatically by writing generation rule using [callback function](http://php.net/manual/en/language.types.callable.php#example-71). Or you can set static **path** or **image name** specifying String values.
Callback function get ActiveRecord model object as argument.

You can specify **image path** (`'modelAttributeFilePath'`) model attribute to save generated path in Data Base. It use only **path** configured as callback function. If this option not set and **path** is callback, image path will be saved to `'modelAttribute'` as *image path* + *image name* string.

```php
public function behaviors()
    {
        'cover' => [
                'class' => CoverBehavior::className(),
                'modelAttribute' => 'cover_name',
                
                'relationAttribute' => 'cover',
                // not required options
                'path' => function ($model) {
                    return 'web/uploads/' . Inflector::slug($model->name) . '/'; 
                    // generated path should have forward slash at the end;
                },
                'modelAttributeFilePath' => 'cover_path',
                'fileNameGenerator' => function ($model) {
                    return Inflector::slug($model->name);
                },
            ],
    }
```

Thumbnails
----------

If you want to use thumbnails:

```php
public function behaviors()
    {
        return [
            'image' => [
                'class' => CoverBehavior::className(),
                'modelAttribute' => 'image_name', // attribute, which will be handled
                // virtual attribute, which is used for image uploading ("image" by default)
                'relationReferenceAttribute' => 'image', 
                'path' => 'uploads/articles/', // path to upload directory 
                'thumbnails' => [
                    [
                        'prefix' => 'thumb_', //image prefix for thumbnail
                        'width' => 220, // max width
                        'height' => 160, // max height
                        'mode' => CoverBehavior::THUMBNAIL_INSET, 
                    ]
                ],
            ],
        ];
    }

```

To see information about mods read [Imagine] (https://imagine.readthedocs.io/)
