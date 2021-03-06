<?php
App::uses("CachedImageModel", "ImgLib.Model");
class ImgLibComponent extends Component {
    var $controller;

    // *** Class variables
    private $image;
    private $width;
    private $height;
    private $imageResized;

    function initialize(Controller $controller) {
        $this->controller = $controller;
    }


    function init($fileName)
    {
        $this->log("opening image: $fileName", "debug");
        // *** Open up the file
        $this->image = $this->openImage($fileName);

        $this->log("getting width of image", LOG_DEBUG);

        // *** Get width and height
        $this->width  = imagesx($this->image);
        $this->height = imagesy($this->image);

        $this->log("end init", LOG_DEBUG);
    }

    function get_image($source, $width, $height, $option) {
        $this->controller->loadModel("ImgLib.CachedImage");
        $image = array();

        $cached_image = $this->controller->CachedImage->find("first",
                array("conditions" => array("CachedImage.filename" => $source,
                                            "CachedImage.width" => $width,
                                            "CachedImage.height" => $height,
                                            "CachedImage.option" => $option)));

        if ($cached_image && file_exists($cached_image["CachedImage"]["absolute_filename"])) {
            CakeLog::write(LOG_DEBUG, "using exisitng cached image: " . Debugger::exportVar($cached_image, 3));
            $resized_filename = $cached_image["CachedImage"]["resized_filename"];
            $dest_filename = $cached_image["CachedImage"]["absolute_filename"];
            $new_height = $cached_image["CachedImage"]["resized_height"];
            $new_width = $cached_image["CachedImage"]["resized_width"];
        } else {
            $this->init($source);

            $dimensions = $this->getDimensions($width, $height, $option);
            $dir_length  = strrpos($source, "/") + 1;
            $dest_dir = substr($source, 0, $dir_length);
            $filename = substr($source, $dir_length, strlen($source));
            $new_width = intval($dimensions["optimalWidth"]);
            $new_height = intval($dimensions["optimalHeight"]);
            $resized_filename = "${new_width}x${new_height}-$filename";
            $dest_filename = "$dest_dir$resized_filename";

            $cached_image = array();
            $cached_image["CachedImage"]["filename"] = $source;
            $cached_image["CachedImage"]["width"] = $width;
            $cached_image["CachedImage"]["height"] = $height;
            $cached_image["CachedImage"]["option"] = $option;
            $cached_image["CachedImage"]["resized_filename"] = $resized_filename;
            $cached_image["CachedImage"]["absolute_filename"] = $dest_filename;
            $cached_image["CachedImage"]["resized_height"] = $new_height;
            $cached_image["CachedImage"]["resized_width"] = $new_width;
            $this->controller->CachedImage->create();
            $this->controller->CachedImage->save($cached_image);

            if (!file_exists($dest_filename)) {
                $this->resizeImage($width, $height, $option);

                $this->log("resizing $source as $dest_filename", "debug"); 
                $this->saveImage($dest_filename);
            } else {
                $this->log("using the cached $dest_filename", "debug");
            }
        }

        return array("filename" => $resized_filename, "absolute_filename" => $dest_filename,
                "height" => $new_height, "width" => $new_width);
    }

    ## --------------------------------------------------------

    private function openImage($file)
    {
        // *** Get extension
        $extension = strtolower(strrchr($file, '.'));
        $size = getimagesize($file);

        switch($size["mime"])
        {
            case 'image/jpeg':
                CakeLog::write(LOG_DEBUG, "creating image from jpeg: '$file'");
                ini_set('display_errors','on');
                  error_reporting(E_ALL);
                $img = @imagecreatefromjpeg($file);
                CakeLog::write(LOG_DEBUG, "created image from jpeg");
                break;
            case 'image/gif':
                $img = @imagecreatefromgif($file);
                break;
            case 'image/png':
                $img = @imagecreatefrompng($file);
                break;
            default:
                $img = false;
                break;
        }
        return $img;
    }

    ## --------------------------------------------------------

    public function resizeImage($newWidth, $newHeight, $option="auto")
    {
        // *** Get optimal width and height - based on $option
        $optionArray = $this->getDimensions($newWidth, $newHeight, $option);

        $optimalWidth  = $optionArray['optimalWidth'];
        $optimalHeight = $optionArray['optimalHeight'];


        // *** Resample - create image canvas of x, y size
        $this->imageResized = imagecreatetruecolor($optimalWidth, $optimalHeight);
        imagecopyresampled($this->imageResized, $this->image, 0, 0, 0, 0, $optimalWidth, $optimalHeight, $this->width, $this->height);


        // *** if option is 'crop', then crop too
        if ($option == 'crop') {
            $this->crop($optimalWidth, $optimalHeight, $newWidth, $newHeight);
        }

        return $optionArray;
    }

    ## --------------------------------------------------------
    
    private function getDimensions($newWidth, $newHeight, $option)
    {

       switch ($option)
        {
            case 'exact':
                $optimalWidth = $newWidth;
                $optimalHeight= $newHeight;
                break;
            case 'portrait':
                $optimalWidth = $this->getSizeByFixedHeight($newHeight);
                $optimalHeight= $newHeight;
                break;
            case 'landscape':
                $optimalWidth = $newWidth;
                $optimalHeight= $this->getSizeByFixedWidth($newWidth);
                break;
            case 'auto':
                $optionArray = $this->getSizeByAuto($newWidth, $newHeight);
                $optimalWidth = $optionArray['optimalWidth'];
                $optimalHeight = $optionArray['optimalHeight'];
                break;
            case 'crop':
                $optionArray = $this->getOptimalCrop($newWidth, $newHeight);
                $optimalWidth = $optionArray['optimalWidth'];
                $optimalHeight = $optionArray['optimalHeight'];
                break;
        }

        $dim = array('optimalWidth' => $optimalWidth, 'optimalHeight' => $optimalHeight);

        CakeLog::write(LOG_DEBUG, "optimal dimensions: " . Debugger::exportVar($dim, 3));

        return $dim;
    }

    ## --------------------------------------------------------

    private function getSizeByFixedHeight($newHeight)
    {
        $ratio = $this->width / $this->height;
        $newWidth = $newHeight * $ratio;
        return $newWidth;
    }

    private function getSizeByFixedWidth($newWidth)
    {
        $ratio = $this->height / $this->width;
        $newHeight = $newWidth * $ratio;

        $this->log("new height: $newWidth * $ratio", "debug");        
        return $newHeight;
    }

    private function getSizeByAuto($newWidth, $newHeight)
    {
        if ($this->height < $this->width)
        // *** Image to be resized is wider (landscape)
        {
            $optimalWidth = $newWidth;
            $optimalHeight= $this->getSizeByFixedWidth($newWidth);
        }
        elseif ($this->height > $this->width)
        // *** Image to be resized is taller (portrait)
        {
            $optimalWidth = $this->getSizeByFixedHeight($newHeight);
            $optimalHeight= $newHeight;
        }
        else
        // *** Image to be resizerd is a square
        {
            if ($newHeight < $newWidth) {
                $optimalWidth = $newWidth;
                $optimalHeight= $this->getSizeByFixedWidth($newWidth);
            } else if ($newHeight > $newWidth) {
                $optimalWidth = $this->getSizeByFixedHeight($newHeight);
                $optimalHeight= $newHeight;
            } else {
                // *** Sqaure being resized to a square
                $optimalWidth = $newWidth;
                $optimalHeight= $newHeight;
            }
        }

        return array('optimalWidth' => $optimalWidth, 'optimalHeight' => $optimalHeight);
    }

    ## --------------------------------------------------------

    private function getOptimalCrop($newWidth, $newHeight)
    {

        $heightRatio = $this->height / $newHeight;
        $widthRatio  = $this->width /  $newWidth;

        if ($heightRatio < $widthRatio) {
            $optimalRatio = $heightRatio;
        } else {
            $optimalRatio = $widthRatio;
        }

        $optimalHeight = $this->height / $optimalRatio;
        $optimalWidth  = $this->width  / $optimalRatio;

        return array('optimalWidth' => $optimalWidth, 'optimalHeight' => $optimalHeight);
    }

    ## --------------------------------------------------------

    private function crop($optimalWidth, $optimalHeight, $newWidth, $newHeight)
    {
        // *** Find center - this will be used for the crop
        $cropStartX = ( $optimalWidth / 2) - ( $newWidth /2 );
        $cropStartY = ( $optimalHeight/ 2) - ( $newHeight/2 );

        $crop = $this->imageResized;
        //imagedestroy($this->imageResized);

        // *** Now crop from center to exact requested size
        $this->imageResized = imagecreatetruecolor($newWidth , $newHeight);
        imagecopyresampled($this->imageResized, $crop , 0, 0, $cropStartX, $cropStartY, $newWidth, $newHeight , $newWidth, $newHeight);
    }

    ## --------------------------------------------------------

    public function saveImage($savePath, $imageQuality="100")
    {
        // *** Get extension
        $extension = strrchr($savePath, '.');
        $extension = strtolower($extension);

        switch($extension)
        {
            case '.jpg':
            case '.jpeg':
                if (imagetypes() & IMG_JPG) {
                    imagejpeg($this->imageResized, $savePath, $imageQuality);
                }
                break;

            case '.gif':
                if (imagetypes() & IMG_GIF) {
                    imagegif($this->imageResized, $savePath);
                }
                break;

            case '.png':
                // *** Scale quality from 0-100 to 0-9
                $scaleQuality = round(($imageQuality/100) * 9);

                // *** Invert quality setting as 0 is best, not 9
                $invertScaleQuality = 9 - $scaleQuality;

                if (imagetypes() & IMG_PNG) {
                     imagepng($this->imageResized, $savePath, $invertScaleQuality);
                }
                break;

            // ... etc

            default:
                // *** No extension - No save.
                break;
        }

        imagedestroy($this->imageResized);
    }

    function get_doc_root($root = null) {
        $doc_root = $this->remove_trailing_slash(env('DOCUMENT_ROOT'));

        if ($root != null) {
            $root = $this->remove_trailing_slash($root);
            $doc_root .=  $root;
        }

        return $doc_root;
    }

    /**
     * Removes the trailing slash from the string specified.
     * @param $string the string to remove the trailing slash from.
     */
    function remove_trailing_slash($string) {
        $string_length = strlen($string);
        if (strrpos($string, "/") === $string_length - 1) {
            $string = substr($string, 0, $string_length - 1);
        }

        return $string;
    }
}
