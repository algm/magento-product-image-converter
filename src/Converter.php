<?php

namespace Algm\MagentoImageConverter;

use Illuminate\Database\Capsule\Manager;
use Intervention\Image\ImageManagerStatic as Image;
use League\Flysystem\Filesystem;

/**
 * Converter class
 */
class Converter
{
    protected $db;
    protected $format;
    protected $basePath;
    protected $updateDatabase = false;
    protected $filesystem;
    protected $sql = "START TRANSACTION;\n";

    /**
     * Constructor
     *
     * @param string $path
     * @param string $database
     * @param string $format
     */
    public function __construct(
        string $path,
        string $database,
        string $format,
        bool $updateDatabase = false,
        Filesystem $filesystem = null
    ) {
        $this->initDb($database);
        $this->format = $format;
        $this->basePath = realpath($path);
        $this->updateDatabase = $updateDatabase;
        $this->filesystem = $filesystem;

        Image::configure(array('driver' => 'imagick'));
    }

    /**
     * Executes the conversion proccess
     *
     * @param callable $callback Execute this function on each iteration
     *
     * @return void
     */
    public function run(callable $callback = null)
    {
        if ($this->updateDatabase) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($this->productImages() as $dbImage) {
                $oldPath = $this->getImagePath($dbImage->value);
                $newImage = $this->processImage($oldPath);

                $this->updateMediaDatabase($dbImage, $newImage);

                if ($callback) {
                    $callback($oldPath, $newImage);
                }
            }

            foreach ($this->productAttributeImages() as $dbImage) {
                try {
                    $oldPath = $this->getImagePath($dbImage->value);
                } catch (\Exception $e) {
                    continue;
                }

                $newImage = $this->processImage($oldPath);

                $this->updateAttributeDatabase($dbImage, $newImage);

                if ($callback) {
                    $callback($oldPath, $newImage);
                }
            }

            if ($this->updateDatabase) {
                $this->db->commit();
            }

            $this->sql .= 'COMMIT;';
            $this->filesystem->put('output.sql', $this->sql);
        } catch (\Exception $e) {
            if ($this->updateDatabase) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Returns the path of the passed image
     *
     * @param string $img
     *
     * @return string
     */
    protected function getImagePath(string $img): string
    {
        $resultPath = $this->basePath . '/media/catalog/product' . $img;

        if (!file_exists($resultPath)) {
            throw new \Exception("File $resultPath not found!");
        }

        return $resultPath;
    }

    /**
     * Process image
     *
     * @param string $imgPath
     *
     * @return string
     */
    protected function processImage(string $imgPath): string
    {
        $ext = pathinfo($imgPath, PATHINFO_EXTENSION);
        $convertedName = str_replace(".$ext", ".{$this->format}", $imgPath);

        Image::make($imgPath)
            ->resize(
                1200,
                1200,
                function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                }
            )
            ->save($convertedName, 85);

        return $convertedName;
    }

    /**
     * Update Database
     *
     * @param object $dbImage
     * @param string $newPath
     *
     * @return void
     */
    protected function updateMediaDatabase($dbImage, $newPath)
    {
        $magentoPath = dirname($dbImage->value);
        $filename = $magentoPath . '/' . last(explode('/', $newPath));

        $this->applyUpdate($dbImage->value_id, $filename, 'catalog_product_entity_media_gallery');
    }
    /**
     * Update Database
     *
     * @param object $dbImage
     * @param string $newPath
     *
     * @return void
     */
    protected function updateAttributeDatabase($dbImage, $newPath)
    {
        $magentoPath = dirname($dbImage->value);
        $filename = $magentoPath . '/' . last(explode('/', $newPath));

        $this->applyUpdate($dbImage->value_id, $filename, 'catalog_product_entity_varchar');
    }

    /**
     * Undocumented function
     *
     * @param string $id
     * @param string $value
     * @param string $table
     *
     * @return void
     */
    protected function applyUpdate(string $id, string $value, string $table)
    {
        $this->writeSqlOutput($id, $value, $table);

        if ($this->updateDatabase) {
            $this->db->table($table)
                ->where('value_id', $id)
                ->update(['value' => $value]);
        }
    }

    /**
     * Undocumented function
     *
     * @param string $id
     * @param string $value
     * @param string $table
     *
     * @return void
     */
    protected function writeSqlOutput(string $id, string $value, string $table)
    {
        $this->sql .= "UPDATE $table SET value = '$value' WHERE value_id = $id LIMIT 1;\n";
    }

    /**
     * Iterates through product images
     *
     * @return void
     */
    protected function productImages()
    {
        $query = $this->db->table('catalog_product_entity_media_gallery')
            ->select('value', 'value_id')
            ->where('value', 'not like', "%.{$this->format}");

        return $query->cursor();
    }

    /**
     * Gets images from the attribute table
     *
     * @return void
     */
    protected function productAttributeImages()
    {
        $attributeIds = $this->db->table('eav_attribute')
            ->select('attribute_id')
            ->whereIn('frontend_label', ['thumbnail', 'image', 'small_image'])
            ->orWhere('frontend_label', 'like', '%small_image')
            ->get()
            ->pluck('attribute_id');

        //dd($attributeIds);

        $query = $this->db->table('catalog_product_entity_varchar')
            ->select('value', 'value_id')
            ->whereNotNull('value')
            ->whereIn('attribute_id', $attributeIds)
            ->where('value', 'not like', "%.{$this->format}");

        return $query->cursor();
    }

    /**
     * Initializes the database
     *
     * @param string $database Database name
     *
     * @return void
     */
    protected function initDb(string $database)
    {
        $capsule = new Manager;

        $capsule->addConnection(
            [
                'driver' => env('DB_DRIVER', 'mysql'),
                'host' => env('DB_HOST', 'localhost'),
                'port' => env('DB_PORT', 3306),
                'database' => $database,
                'username' => env('DB_USERNAME', 'user'),
                'password' => env('DB_PASSWORD', 'password'),
                'charset' => 'utf8',
                'collation' => 'utf8_unicode_ci',
                'prefix' => env('DB_PREFIX', ''),
            ]
        );

        $this->db = $capsule->getConnection();
    }
}
