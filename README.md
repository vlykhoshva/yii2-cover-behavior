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
"yii2tech/ar-linkmany": "*"
```

to the require section of your composer.json.


Usage
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
