<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait UploadField
{
    /**
     * Upload directory.
     *
     * @var string
     */
    protected $directory = '';

    /**
     * File name.
     *
     * @var null
     */
    protected $name = null;

    /**
     * Storage instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $storage = '';

    /**
     * If use unique name to store upload file.
     *
     * @var bool
     */
    protected $useUniqueName = false;

    /**
     * If use sequence name to store upload file.
     *
     * @var bool
     */
    protected $useSequenceName = false;

    /**
     * Retain file when delete record from DB.
     *
     * @var bool
     */
    protected $retainable = false;

    /**
     * Configuration for setting up file actions for newly selected file thumbnails in the preview window.
     *
     * @var array
     */
    protected $fileActionSettings = [
        'showRemove' => false,
        'showDrag'   => false,
    ];

    /**
     * Controls the storage permission. Could be 'private' or 'public'.
     *
     * @var string
     */
    protected $storagePermission;

    /**
     * Initialize the storage instance.
     *
     * @return void.
     */
    protected function initStorage()
    {
        $this->disk(config('admin.upload.disk'));
    }

    /**
     * Set default options form image field.
     *
     * @return void
     */
    protected function setupDefaultOptions()
    {
        $defaults = [
            'overwriteInitial'     => false,
            'initialPreviewAsData' => true,
            'browseLabel'          => trans('admin.browse'),
            'cancelLabel'          => trans('admin.cancel'),
            'showRemove'           => false,
            'showUpload'           => false,
            'dropZoneEnabled'      => false,
            'deleteExtraData'      => [
                $this->formatName($this->column) => static::FILE_DELETE_FLAG,
                static::FILE_DELETE_FLAG         => '',
                '_token'                         => csrf_token(),
                '_method'                        => 'PUT',
            ],
        ];

        if ($this->form instanceof Form) {
            $defaults['deleteUrl'] = $this->form->resource().'/'.$this->form->model()->getKey();
        }

        $defaults = array_merge($defaults, ['fileActionSettings' => $this->fileActionSettings]);

        $this->options($defaults);
    }

    /**
     * Set preview options form image field.
     *
     * @return void
     */
    protected function setupPreviewOptions()
    {
        $initialPreviewConfig = $this->initialPreviewConfig();

        $this->options(compact('initialPreviewConfig'));
    }

    /**
     * Allow use to remove file.
     *
     * @return $this
     */
    public function removable()
    {
        $this->fileActionSettings['showRemove'] = true;

        return $this;
    }

    /**
     * Indicates if the underlying field is retainable.
     *
     * @return $this
     */
    public function retainable($retainable = true)
    {
        $this->retainable = $retainable;

        return $this;
    }

    /**
     * Set options for file-upload plugin.
     *
     * @param array $options
     *
     * @return $this
     */
    public function options($options = [])
    {
        $this->options = array_merge($options, $this->options);

        return $this;
    }

    /**
     * Set disk for storage.
     *
     * @param string $disk Disks defined in `config/filesystems.php`.
     *
     * @throws \Exception
     *
     * @return $this
     */
    public function disk($disk)
    {
        try {
            $this->storage = Storage::disk($disk);
        } catch (\Exception $exception) {
            if (!array_key_exists($disk, config('filesystems.disks'))) {
                admin_error(
                    'Config error.',
                    "Disk [$disk] not configured, please add a disk config in `config/filesystems.php`."
                );

                return $this;
            }

            throw $exception;
        }

        return $this;
    }

    /**
     * Specify the directory and name for upload file.
     *
     * @param string      $directory
     * @param null|string $name
     *
     * @return $this
     */
    public function move($directory, $name = null)
    {
        $this->dir($directory);

        $this->name($name);

        return $this;
    }

    /**
     * Specify the directory upload file.
     *
     * @param string $dir
     *
     * @return $this
     */
    public function dir($dir)
    {
        if ($dir) {
            $this->directory = $dir;
        }

        return $this;
    }

    /**
     * Set name of store name.
     *
     * @param string|callable $name
     *
     * @return $this
     */
    public function name($name)
    {
        if ($name) {
            $this->name = $name;
        }

        return $this;
    }

    /**
     * Use unique name for store upload file.
     *
     * @return $this
     */
    public function uniqueName()
    {
        $this->useUniqueName = true;

        return $this;
    }

    /**
     * Use sequence name for store upload file.
     *
     * @return $this
     */
    public function sequenceName()
    {
        $this->useSequenceName = true;

        return $this;
    }

    /**
     * Get store name of upload file.
     *
     * @param UploadedFile $file
     *
     * @return string
     */
    protected function getStoreName(UploadedFile $file)
    {
        if ($this->useUniqueName) {
            return $this->generateUniqueName($file);
        }

        if ($this->useSequenceName) {
            return $this->generateSequenceName($file);
        }

        if ($this->name instanceof \Closure) {
            return $this->name->call($this, $file);
        }

        if (is_string($this->name)) {
            return $this->name;
        }

        return $file->getClientOriginalName();
    }

    /**
     * Get directory for store file.
     *
     * @return mixed|string
     */
    public function getDirectory()
    {
        if ($this->directory instanceof \Closure) {
            return call_user_func($this->directory, $this->form);
        }

        return $this->directory ?: $this->defaultDirectory();
    }

    /**
     * Upload file and delete original file.
     *
     * @param UploadedFile $file
     *
     * @return mixed
     */
    protected function upload(UploadedFile $file)
    {
        $this->renameIfExists($file);

        if (!is_null($this->storagePermission)) {
            return $this->storage->putFileAs($this->getDirectory(), $file, $this->name, $this->storagePermission);
        }

        return $this->storage->putFileAs($this->getDirectory(), $file, $this->name);
    }

    /**
     * If name already exists, rename it.
     *
     * @param $file
     *
     * @return void
     */
    public function renameIfExists(UploadedFile $file)
    {
        if ($this->storage->exists("{$this->getDirectory()}/$this->name")) {
            $this->name = $this->generateUniqueName($file);
        }
    }

    /**
     * Get file visit url.
     *
     * @param $path
     *
     * @return string
     */
    public function objectUrl($path)
    {
        if (URL::isValidUrl($path)) {
            return $path;
        }

        if ($this->storage) {
            return $this->storage->url($path);
        }

        return Storage::disk(config('admin.upload.disk'))->url($path);
    }

    /**
     * Generate a unique name for uploaded file.
     *
     * @param UploadedFile $file
     *
     * @return string
     */
    protected function generateUniqueName(UploadedFile $file)
    {
        return md5(uniqid()).'.'.$file->getClientOriginalExtension();
    }

    /**
     * Generate a sequence name for uploaded file.
     *
     * @param UploadedFile $file
     *
     * @return string
     */
    protected function generateSequenceName(UploadedFile $file)
    {
        $index = 1;
        $extension = $file->getClientOriginalExtension();
        $original = $file->getClientOriginalName();
        $new = sprintf('%s_%s.%s', $original, $index, $extension);

        while ($this->storage->exists("{$this->getDirectory()}/$new")) {
            $index++;
            $new = sprintf('%s_%s.%s', $original, $index, $extension);
        }

        return $new;
    }

    /**
     * Destroy original files.
     *
     * @return void.
     */
    public function destroy()
    {
        if (!$this->retainable && $this->storage->exists($this->original)) {
            $this->storage->delete($this->original);
        }
    }

    /**
     * Set file permission when stored into storage.
     *
     * @param string $permission
     *
     * @return $this
     */
    public function storagePermission($permission)
    {
        $this->storagePermission = $permission;

        return $this;
    }
}
