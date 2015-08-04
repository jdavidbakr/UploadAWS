<?php
/**
 * Conversion of the old upload_aws class from the global repo.
 * Handles management of the AWS files, with utitilies to resize images
 * and generate signed URLs.
 *
 * Utilizes aws/aws-sdk-php-laravel.  Optionally add a 'bucket' key to the aws config file.
 *
 * @todo: Could probably extend the use of the UploadFile class beyond the constructor
 */

namespace jdavidbakr\UploadAWS;

use AWS;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadAWS {

	protected $bucket;
	protected $dir;
	protected $local_file;
	protected $location;
    protected $mime = null;
    protected $storage = 'STANDARD';
    protected $encryption = 'AES256';

    /**
     * Constructor accepts a array (as passed by a $_FILES data from a form)
     * or a string (to reference an existing file in the store)
     * @param UploadedFile/string  $file   
     * @param string $bucket
     */	
	public function __construct($file = null, $bucket = null)
	{
		$this->bucket = !empty($bucket)?$bucket:config('aws.bucket');
		if(is_object($file)) {
			// Upload the file passed to us by $file
			$filename = str_replace("'","",$this->new_filename($file->getClientOriginalName()));
			$dir = $this->dir;
			if(file_exists($file->getRealPath())) {
				$this->local_file = tempnam(sys_get_temp_dir(),'awsupload') . $filename;
				if(!move_uploaded_file($file->getRealPath(), $this->local_file)) {
					if(!copy($file->getRealPath(), $this->local_file)) {
						abort(500,'Failed to copy '.$file->getRealPath().' to '.$this->local_file);
					}
				}
				$this->mime = $file->getClientMimeType();
				$this->location = $dir.'/'.$filename;
				$this->image_size();
			} else {
				$this->local_file = null;
			}
		} else {
			$this->location = $file;
		}
	}

	/**
	 * Destructor uploads and deletes any local file
	 */
	public function __destruct()
	{
		$this->delete_local_file();
	}

	/**
	 * Accessor to change the bucket we are using
	 * @param string $bucket 
	 */
    public function set_bucket($bucket)
    {
        $this->bucket = $bucket;
    }

    /**
     * Generate a filename in the tmp directory
     * @return string new filename
     */
	public function new_local_file_name()
	{
		$name = tempnam(sys_get_tem_dir(), 'awsupload');
		return $name;
	}

	/**
	 * Uploades the local file, if it exists, and then deletes it.
	 * @return null 
	 */
	public function delete_local_file()
	{
		if(file_exists($this->local_file)) {
			$this->upload_local_file();
			unlink($this->local_file);
		}
		$this->local_file = null;
	}

	/**
	 * Loads a file from a URL
	 * @param  string $url 
	 * @param  string $ext 
	 * @return null      
	 */
	public function load_from_url($url, $ext = '')
	{
		// Delete the local file if it exists
		$this->delete_local_file();
		if($ext) {
			$ext = '.'.$ext;
		}
        $filename = str_replace("'", "", $this->new_filename($url . $ext));
        $this->local_file = $this->new_local_file_name();
        file_put_contents($this->local_file, file_get_contents($url));
        $this->location = $this->dir . '/' . $filename;
	}

	/**
	 * Uploads the local copy of our file to S3
	 * @return null 
	 */
	public function upload_local_file()
	{
        if ($this->local_file) {
        	$s3 = AWS::createClient('s3');
            $s3->putObject(
                    array(
                        'Bucket' => $this->bucket,
                        'Key' => $this->location,
                        'SourceFile' => $this->local_file,
                        'ContentType' => $this->mime,
                        'StorageClass' => $this->storage,
                        'ServerSideEncryption' => $this->encryption
                    )
            );
        }
	}

	/**
	 * Returns the local file's filename
	 * for processes that need to know it.
	 * Will download the file if it is not already here.
	 * @return string 
	 */
	public function local_filename()
	{
        if (!$this->local_file) {
            $this->get_local_file();
        }
        return $this->local_file;
	}

	/**
	 * Downloads the file from S3 and stores it locally
	 * @return null 
	 */
	public function get_local_file()
	{
        $this->delete_local_file();
        // Downloads a local copy of the file.
        $pieces = explode(".", $this->location);
        $this->local_file = tempnam(sys_get_temp_dir(), 'awsupload') . '.' . $pieces[count($pieces) - 1];
        $s3 = AWS::createClient('s3');
        $s3->getObject(
                array(
                    'Bucket' => $this->bucket,
                    'Key' => $this->location,
                    'SaveAs' => $this->local_file
                )
        );
        // Wait until the file is downloaded
        $size = -10;
        do {
            $old_size = $size;
            sleep(0.25);
            $size = filesize($this->local_file);
        } while ($old_size != $size);
	}

	/**
	 * Processes the file and gets the image size info
	 * @return array result of getimagesize()
	 */
	public function image_size()
	{
        if (!$this->local_file) {
            $this->get_local_file();
        }
        $image_size = getimagesize($this->local_file);
        $this->mime = $image_size['mime'];
        return $image_size;
	}

	/**
	 * Generates a new filename for S3, retaining the extention of the original file
	 * @param  string $name The original file
	 * @return string       The S3 location
	 */
	public function new_filename($name)
	{
        $name = preg_replace("/[^a-zA-Z0-9._]/", "_", $name);

        // First make sure that we have a directory to put it in, make a new directory for each month
        // Using base64_encode to get more interesting results than an md5 hash
        $dir = date("Ym");
        $file_split = explode(".", $name);
        $extension = $file_split[count($file_split) - 1];
        $s3 = AWS::createClient('s3');
        do {
            $filename = substr(base64_encode(md5(uniqid(rand()))), 0, 8) . "." . $extension;
        } while ($s3->doesObjectExist($this->bucket, $dir . '/' . $filename));
        $this->dir = $dir;
        return $filename;
	}

	/**
	 * Accessor for the S3 path
	 * @return string 
	 */
	public function get_location()
	{
		return $this->location;
	}

	/**
	 * Generates a signed URL for the object.
	 * @param  array   $opt    Options to pass to getObjectURL
	 * @param  integer $expire Number of seconds to expire
	 * @return string          
	 */
	public function get_url($opt = array(), $expire = 2000)
	{
		$time = time() + $expire;
		// Round to the nearest 1,000 seconds so we can utilize some level of browser cache
        $time = intval(ceil($time / 1000) * 1000);
        $opt['https'] = true;
        $s3 = AWS::createClient('s3');
        $url = $s3->getObjectURL($this->bucket, $this->location, $time, $opt);
        return $url;
	}

	/**
	 * Loads the image for the file
	 * @return image GD image or NULL on failure
	 */
	public function load_image()
	{
        if (file_exists($this->local_file)) {
            $image_size = $this->image_size();
            if (preg_match('/jpeg/', $image_size['mime'])) {
                $file = imagecreatefromjpeg($this->local_file);
            } elseif (preg_match('/gif/', $image_size['mime'])) {
                $file = imagecreatefromgif($this->local_file);
            } elseif (preg_match('/png/', $image_size['mime'])) {
                $file = imagecreatefrompng($this->local_file);
            } else {
            	$file = null;
            }
            return $file;
        }
	}

	/**
	 * Resize the image without concern about ratio
	 * @param  integer $width  
	 * @param  integer $weight 
	 * @return null         
	 */
	public function resize_image($width, $weight)
	{
        $image_size = $this->image_size();
        if ($image_size[0] == $width && $image_size[1] == $height) {
            // Already correct size,
            return;
        }
        if (!$image_size[0]) {
        	abort(500, "Unable to load remote image");
        }

        // Create a new image for the resized image
        $newimage = imagecreatetruecolor($width, $height);
        $oldimage = $this->load_image();
        imagecopyresampled($newimage, $oldimage, 0, 0, 0, 0, $width, $height, $image_size[0], $image_size[1]);

        $filename = $this->new_local_file_name();
        if (preg_match('/jpeg/', $image_size['mime'])) {
            imagejpeg($newimage, $filename, 100);
        } elseif (preg_match('/gif/', $image_size['mime'])) {
            imagegif($newimage, $filename);
        } elseif (preg_match('/png/', $image_size['mime'])) {
            imagepng($newimage, $filename);
        }
        $this->delete_local_file();
        $this->local_file = $filename;
	}

	/**
	 * Resizes the image so that it fits within max_width and max_height,
	 * but doesn't change the ratio of the image.
	 * @param  integer $max_width  
	 * @param  integer $max_height 
	 * @return null             
	 */
	public function get_max_size($max_width, $max_height)
	{
        $image_size = $this->image_size();
        $width = $image_size[0];
        $height = $image_size[1];
        if ($width <= $max_width && $height <= $max_height) {
            // Already within our max size
            return;
        }
        if ($width > $max_width) {
            // Wide image, set width to max width and adjust height accordingly
            $height = ($height * $max_width) / $width;
            $width = $max_width;
        }
        if ($height > $max_height) {
            $width = ($width * $max_height) / $height;
            $height = $max_height;
        }
        // Create a new image for this with the image at this size
        $newimage = imagecreatetruecolor($width, $height);
        $oldimage = $this->load_image();
        // Place the original image into this image
        imagecopyresampled($newimage, $oldimage, 0, 0, 0, 0, $width, $height, $image_size[0], $image_size[1]);
        $filename = $this->new_local_file_name();
        if (preg_match('/jpeg/', $image_size['mime'])) {
            imagejpeg($newimage, $filename, 100);
        } elseif (preg_match('/gif/', $image_size['mime'])) {
            imagegif($newimage, $filename);
        } elseif (preg_match('/png/', $image_size['mime'])) {
            imagepng($newimage, $filename);
        }
        $this->delete_local_file();
        $this->local_file = $filename;
	}

	/**
	 * Scales the image to $x by $y, applying pillarbox or letterbox
	 * @param  integer $x 
	 * @param  integer $y 
	 * @return null   
	 */
	public function scale_image($x, $y)
	{
        $newimage = imagecreatetruecolor($x, $y); // defaults to filled with black
        $image_size = $this->image_size();
        if ($image_size[0] > $image_size[1]) {
            // Wide image, we want to resize the image with a letterbox
            $width = $x;
            $height = ($image_size[1] * $x) / $image_size[0];
            $oldimage = $this->load_image();
            $y = ($y - $height) / 2;
            $x = 0;
        } else {
            // Tall image, we want to resize the image with a pillarbox
            $height = $y;
            $width = ($image_size[0] * $y) / $image_size[1];
            $oldimage = $this->load_image();
            $y = 0;
            $x = ($x - $width) / 2;
        }
        imagecopyresampled($newimage, $oldimage, $x, $y, 0, 0, $width, $height, $image_size[0], $image_size[1]);
        $filename = tempnam(sys_get_temp_dir(), 'img');
        if (preg_match('/jpeg/', $image_size['mime'])) {
            imagejpeg($newimage, $filename, 100);
        } elseif (preg_match('/gif/', $image_size['mime'])) {
            imagegif($newimage, $filename);
        } elseif (preg_match('/png/', $image_size['mime'])) {
            imagepng($newimage, $filename);
        }
        $this->delete_local_file();
        $this->local_file = $filename;
	}

	/**
	 * Resize the image to fit in the box $w x $h, cropping to fill the entire image
	 * @param  integer $w 
	 * @param  integer $h 
	 * @return null    
	 */
	public function crop_image($w, $h)
	{
        // This function resizes this image to fit in the box $w x $h, cropping to fill the entire image
        $image_size = $this->image_size();
        if ($image_size[0] == $w && $image_size[1] == $h) {
            // No need to crop
            return;
        }
        $ratio = $image_size[0] / $image_size[1];
        $goal_ratio = $w / $h;

        // Calculate the new image size
        if ($ratio < $goal_ratio) {
            // Tall image, crop top and bottom
            $l = 0;
            $r = $image_size[0];
            $height = $image_size[0] / $goal_ratio;
            $t = ($image_size[1] / 2) - ($height / 2);
            $b = ($image_size[1] / 2) + ($height / 2);
        } else {
            // Wide image, crop top and bottom
            $t = 0;
            $b = $image_size[1];
            $width = $image_size[1] * $goal_ratio;
            $l = ($image_size[0] / 2) - ($width / 2);
            $r = ($image_size[0] / 2) + ($width / 2);
        }

        // Create a new image for the resized image
        $oldimage = $this->load_image();
        $newimage = imagecreatetruecolor($w, $h);
        imagecopyresampled($newimage, $oldimage, 0, 0, $l, $t, $w, $h, $r - $l, $b - $t);
        $filename = tempnam(sys_get_temp_dir(), 'img');
        if (preg_match('/jpeg/', $image_size['mime'])) {
            imagejpeg($newimage, $filename, 100);
        } elseif (preg_match('/gif/', $image_size['mime'])) {
            imagegif($newimage, $filename);
        } elseif (preg_match('/png/', $image_size['mime'])) {
            imagepng($newimage, $filename);
        }
        $this->delete_local_file();
        $this->local_file = $filename;
	}

	/**
	 * Crop the image with complete control over the coordinates
	 * @param  integer  $top           
	 * @param  integer  $left          
	 * @param  integer  $width         
	 * @param  integer  $height        
	 * @param  boolean $same_location if true, will not change the remote filename
	 * @return null                 
	 */
	public function crop($top, $left, $width, $height, $same_location = false)
	{
        if (!$this->local_file) {
            $this->get_local_file();
        }
        $url = $this->local_file;
        $image_size = $this->image_size();
        if ($url) {
            $oldimage = $this->load_image();
            $newimage = imagecreatetruecolor($width, $height);
            imagecopyresampled($newimage, $oldimage, 0, 0, $top, $left, $width, $height, $width, $height);
            // Delete the existing image
            if (!$same_location) {
                upload_aws2::$client->deleteObject(
                        array(
                            'Bucket' => $this->bucket,
                            'Key' => $this->location
                        )
                );
                // Create a new filename
                $this->location = $this->new_filename($this->location);
                $this->location = $this->dir . '/' . $this->location;
            }
            $filename = $this->new_local_file_name();
            if (preg_match('/jpeg/', $image_size['mime'])) {
                imagejpeg($newimage, $filename, 100);
            } elseif (preg_match('/gif/', $image_size['mime'])) {
                imagegif($newimage, $filename);
            } elseif (preg_match('/png/', $image_size['mime'])) {
                imagepng($newimage, $filename);
            }
            $this->delete_local_file();
            $this->local_file = $filename;
        }
	}

	/**
	 * Copy the file to a new location without deleting the old file
	 * @param  string $prefix Prefix to be applied to the new filename
	 * @return null
	 */
	public function copy($prefix = null)
	{
        $this->upload_local_file();

        // Copies the file, returns the new location
        $file_split = explode("/", $this->location);
        $source = $file_split[count($file_split) - 1];
        $filename = $this->new_filename($source);
        $dir = $this->dir;
        if (!empty($prefix)) {
            $dir = "{$prefix}/{$dir}";
        }
        upload_aws2::$client->copyObject(
                array(
                    'Bucket' => $this->bucket,
                    'CopySource' => $this->bucket . '/' . $this->location,
                    'Key' => "{$dir}/{$filename}",
                    'StorageClass' => $this->storage,
                    'ServerSiteEncryption' => $this->encryption
                )
        );
        $this->location = $dir . '/' . $filename;
        // Make sure the local file is uploaded
        $this->upload_local_file();
        return $this->location;
	}

	/**
	 * Deletes the file from S3
	 * @return null 
	 */
	public function delete()
	{
		$s3 = AWS::createClient('s3');
        $s3->deleteObject(
                array(
                    'Bucket' => $this->bucket,
                    'Key' => $this->location
                )
        );
		if($this->local_file && file_exists($this->local_file)) {
			unlink($this->local_file);
		}
        $this->local_file = null;
	}

	/**
	 * Returns the actual size of the file
	 * @return integer 
	 */
	public function get_file_size()
	{
        if (!$this->file_size) {
            if ($this->local_file) {
                $this->file_size = filesize($this->local_file);
            } else {
                $object = upload_aws2::$client->listObjects(array(
                    'Bucket' => $this->bucket,
                    'Prefix' => $this->location
                ));
                $this->file_size = $object['Contents'][0]['Size'];
            }
        }
        return $this->file_size;
	}

}
