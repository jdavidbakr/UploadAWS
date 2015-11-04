# UploadAWS

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Total Downloads][ico-downloads]][link-downloads]

A wrapper class to handle images and uploading them to AWS. Features such as resizing, cropping, etc are included.  A random remote filename is generated, if you want to retain the original filename you should do so in the database.  This is to prevent file name collisions remotely; the class passes back the random remote filename.

Requires the PHP-GD library.

This should work outside of Laravel but I built it for Laravel, so your mileage may vary.

To process an uploaded file:

```php
// Instantiate with the $_FILE array.
// Uses config('aws.bucket') or you can pass the bucket as the second argument of the constructor.
$upload = new \jdavidbakr\UploadAWS($_FILE['form_name']);

// Retrieve the location of the uploaded file and store it somewhere
$location = $upload->get_location();
```

To work with a file that has been uploaded:
```php
// Instantiate with the remote file path
$upload = new \jdavidbakr\UploadAWS($location);
```

Once you have a file, you can perform several operations on it, especially if it's an image file:
```php
// Get a temporary signed URL
$url = $upload->get_url();
// Resize the image
$upload->resize_image(640,480);
// Resize the image so that it fits in the max size
$upload->get_max_size(1000,1000);
// Scale image, applies pillarbox or letterbox to retain the aspect ratio
$upload->scale_image(640,480);
// Crop the image to this size, will retain the current image center
$upolad->crop_image(640,480);
// Crop the image with full control over what part of the image to keep
$upload->crop($top, $left, $width, $height);
// Copy the image into a new file location
$upload->copy();
// Delete the remote file
$upload->delete();
// Get the actual size of the file
$upload->get_file_size();
```

[ico-version]: https://img.shields.io/packagist/v/jdavidbakr/upload-aws.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/jdavidbakr/upload-aws.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/jdavidbakr/upload-aws
[link-downloads]: https://packagist.org/packages/jdavidbakr/upload-aws
[link-author]: https://github.com/jdavidbakr
[link-contributors]: ../../contributors
