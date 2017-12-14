<?php

namespace nntmux;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Exception\ImageException;
use Intervention\Image\Exception\NotReadableException;
use Intervention\Image\Exception\NotWritableException;
use Intervention\Image\ImageManager;

/**
 * Resize/save/delete images to disk.
 *
 * Class ReleaseImage
 */
class ReleaseImage
{
    /**
     * Path to save ogg audio samples.
     *
     * @var string
     */
    public $audSavePath;

    /**
     * Path to save video preview jpg pictures.
     *
     * @var string
     */
    public $imgSavePath;

    /**
     * Path to save large jpg pictures(xxx).
     *
     * @var string
     */
    public $jpgSavePath;

    /**
     * Path to save movie jpg covers.
     *
     * @var string
     */
    public $movieImgSavePath;

    /**
     * Path to save video ogv files.
     *
     * @var string
     */
    public $vidSavePath;

    /**
     * ReleaseImage constructor.
     */
    public function __construct()
    {
        $this->audSavePath = NN_COVERS.'audiosample'.DS;
        $this->imgSavePath = NN_COVERS.'preview'.DS;
        $this->jpgSavePath = NN_COVERS.'sample'.DS;
        $this->movieImgSavePath = NN_COVERS.'movies'.DS;
        $this->vidSavePath = NN_COVERS.'video'.DS;
    }

    /**
     * @param $imgLoc
     * @return bool|\Intervention\Image\Image
     */
    protected function fetchImage($imgLoc)
    {
        try {
            $img = (new ImageManager())->make($imgLoc);
        } catch (NotReadableException $e) {
            if ($e->getCode() === 404) {
                ColorCLI::doEcho(ColorCLI::notice('Data not available on server'));
            } elseif ($e->getCode() === 503) {
                ColorCLI::doEcho(ColorCLI::notice('Service unavailable'));
            } else {
                ColorCLI::doEcho(ColorCLI::notice('Unable to fetch data, server responded with code: '.$e->getCode()));
            }

            return false;
        } catch (ImageException $e) {
            ColorCLI::doEcho(ColorCLI::notice('Image error: '.$e->getCode()));

            return false;
        }

        return $img;
    }

    /**
     * Save an image to disk, optionally resizing it.
     *
     * @param string $imgName      What to name the new image.
     * @param string $imgLoc       URL or location on the disk the original image is in.
     * @param string $imgSavePath  Folder to save the new image in.
     * @param string $imgMaxWidth  Max width to resize image to.   (OPTIONAL)
     * @param string $imgMaxHeight Max height to resize image to.  (OPTIONAL)
     * @param bool   $saveThumb    Save a thumbnail of this image? (OPTIONAL)
     *
     * @return int 1 on success, 0 on failure Used on site to check if there is an image.
     */
    public function saveImage($imgName, $imgLoc, $imgSavePath, $imgMaxWidth = '', $imgMaxHeight = '', $saveThumb = false): int
    {
        // Try to get the image as a string.
        $cover = $this->fetchImage($imgLoc);
        if ($cover === false) {
            return 0;
        }

        // Check if we need to resize it.
        if ($imgMaxWidth !== '' && $imgMaxHeight !== '') {
            $width = $cover->width();
            $height = $cover->height();
            $ratio = min($imgMaxHeight / $height, $imgMaxWidth / $width);
            // New dimensions
            $new_width = (int) ($ratio * $width);
            $new_height = (int) ($ratio * $height);
            if ($new_width < $width && $new_width > 10 && $new_height > 10) {
                $cover->resize($new_width, $new_height);

                if ($saveThumb) {
                    $cover->save($imgSavePath.$imgName.'_thumb.jpg');
                }
            }
        }
        // Store it on the hard drive.
        $coverPath = $imgSavePath.$imgName.'.jpg';
        try {
            $cover->save($coverPath);
        } catch (NotWritableException $e) {
            return 0;
        }
        // Check if it's on the drive.
        if (! is_file($coverPath)) {
            return 0;
        }

        return 1;
    }

    /**
     * Delete images for the release.
     *
     * @param string $guid The GUID of the release.
     *
     * @return void
     */
    public function delete($guid): void
    {
        $thumb = $guid.'_thumb.jpg';

        Storage::delete($this->audSavePath.$guid.'.ogg', $this->imgSavePath.$thumb, $this->jpgSavePath.$thumb, $this->vidSavePath.$guid.'.ogv');
    }
}
