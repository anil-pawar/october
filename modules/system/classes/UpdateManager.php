<?php namespace System\Classes;

use App;
use URL;
use File;
use Lang;
use Http;
use Schema;
use Config;
use Carbon\Carbon;
use System\Models\Parameters;
use System\Models\PluginVersion;
use System\Console\CacheClear;
use System\Classes\ApplicationException;
use October\Rain\Filesystem\Zip;
use Exception;

/**
 * Update manager
 *
 * Handles the CMS install and update process.
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
class UpdateManager
{
    use \October\Rain\Support\Traits\Singleton;

    /**
     * The notes for the current operation.
     * @var array
     */
    protected $notes = [];

    /**
     * @var string Application base path.
     */
    protected $baseDirectory;

    /**
     * @var string A temporary working directory.
     */
    protected $tempDirectory;

    /**
     * @var System\Classes\PluginManager
     */
    protected $pluginManager;

    /**
     * @var System\Classes\VersionManager
     */
    protected $versionManager;

    /**
     * @var string Secure API Key
     */
    protected $key;

    /**
     * @var string Secure API Secret
     */
    protected $secret;

    /**
     * Initialize this singleton.
     */
    protected function init()
    {
        $this->pluginManager = PluginManager::instance();
        $this->versionManager = VersionManager::instance();
        $this->migrator = App::make('migrator');
        $this->repository = App::make('migration.repository');
        $this->tempDirectory = Config::get('cms.tempDir', sys_get_temp_dir());
        $this->baseDirectory = PATH_BASE;

        /*
         * Ensure temp directory exists
         */
        if (!File::isDirectory($this->tempDirectory))
            File::makeDirectory($this->tempDirectory, 0777, true);
    }

    /**
     * Creates the migration table and updates
     * @return self
     */
    public function update()
    {
        $firstUp = !Schema::hasTable('migrations');
        if ($firstUp) {
            $this->repository->createRepository();
            $this->note('Migration table created successfully.');
        }

        /*
         * Update modules
         */
        $modules = Config::get('cms.loadModules', []);
        foreach ($modules as $module)
            $this->migrateModule($module);

        /*
         * Update plugins
         */
        $plugins = $this->pluginManager->getPlugins();
        foreach ($plugins as $name => $plugin) {
            $this->updatePlugin($name);
        }

        Parameters::set('system::update.count', 0);
        CacheClear::fireInternal();

        /*
         * Seed modules
         */
        if ($firstUp) {
            $modules = Config::get('cms.loadModules', []);
            foreach ($modules as $module)
                $this->seedModule($module);
        }

        return $this;
    }

    /**
     * Checks for new updates and returns the amount of unapplied updates.
     * Only requests from the server at a set interval (retry timer).
     * @param  boolean $force Ignore the retry timer.
     * @return int            Number of unapplied updates.
     */
    public function check($force = false)
    {
        /*
         * Already know about updates, never retry.
         */
        $oldCount = Parameters::get('system::update.count');
        if ($oldCount > 0)
            return $oldCount;

        /*
         * Retry period not passed, skipping.
         */
        if (!$force && ($retryTimestamp = Parameters::get('system::update.retry'))) {
            if (Carbon::createFromTimeStamp($retryTimestamp)->isFuture())
                return $oldCount;
        }

        try {
            $result = $this->requestUpdateList();
            $newCount = array_get($result, 'update', 0);
        }
        catch (Exception $ex) {
            $newCount = 0;
        }

        /*
         * Remember update count, set retry date
         */
        Parameters::set('system::update.count', $newCount);
        Parameters::set('system::update.retry', Carbon::now()->addHours(24)->timestamp);

        return $newCount;
    }

    /**
     * Requests an update list used for checking for new updates.
     * @param  boolean $force Request application and plugins hash list regardless of version.
     * @return array
     */
    public function requestUpdateList($force = false)
    {
        $installed = PluginVersion::all();
        $versions = $installed->lists('version', 'code');
        $names = $installed->lists('name', 'code');

        $params = [
            'core' => $this->getHash(),
            'plugins' => serialize($versions),
            'force' => $force
        ];

        if ($projectId = Parameters::get('system::project.id')) {
            $params['project'] = $projectId;
        }

        $result = $this->requestServerData('core/update', $params);

        /*
         * Inject known core build
         */
        if ($core = array_get($result, 'core')) {
            $core['old_build'] = Parameters::get('system::core.build');
            $result['core'] = $core;
        }

        /*
         * Inject the application's known plugin name and version
         */
        $plugins = [];
        foreach (array_get($result, 'plugins', []) as $code => $info) {
            $info['name'] = isset($names[$code]) ? $names[$code] : $code;
            $info['old_version'] = isset($versions[$code]) ? $versions[$code] : false;
            $plugins[$code] = $info;
        }
        $result['plugins'] = $plugins;

        Parameters::set('system::update.count', array_get($result, 'update', 0));

        return $result;
    }

    /**
     * Requests details about a project based on its identifier.
     * @param  string $projectId
     * @return array
     */
    public function requestProjectDetails($projectId)
    {
        $result = $this->requestServerData('project/detail', ['id' => $projectId]);
        return $result;
    }

    /**
     * Roll back all modules and plugins.
     * @return self
     */
    public function uninstall()
    {
        /*
         * Rollback plugins
         */
        $plugins = $this->pluginManager->getPlugins();
        foreach ($plugins as $name => $plugin) {
            $this->rollbackPlugin($name);
        }

        /*
         * Register module migration files
         */
        $modules = Config::get('cms.loadModules', []);
        foreach ($modules as $module) {
            $path = PATH_BASE . '/modules/'.strtolower($module).'/database/migrations';
            $this->migrator->requireFiles($path, $this->migrator->getMigrationFiles($path));
        }

        /*
         * Rollback modules
         */
        while (true) {
            $count = $this->migrator->rollback();

            foreach ($this->migrator->getNotes() as $note) {
                $this->note($note);
            }

            if ($count == 0) break;
        }

        Schema::dropIfExists('migrations');

        return $this;
    }

    //
    // Modules
    //

    /**
     * Returns the currently installed system hash.
     * @return string
     */
    public function getHash()
    {
        return Parameters::get('system::core.hash', md5('NULL'));
    }

    /**
     * Run migrations on a single module
     * @param string $module Module name
     * @return self
     */
    public function migrateModule($module)
    {
        $this->migrator->run(PATH_BASE . '/modules/'.strtolower($module).'/database/migrations');

        $this->note($module);
        foreach ($this->migrator->getNotes() as $note) {
            $this->note(' - '.$note);
        }
        return $this;
    }

    /**
     * Run seeds on a module
     * @param string $module Module name
     * @return self
     */
    public function seedModule($module)
    {
        $className = '\\'.$module.'\Database\Seeds\DatabaseSeeder';
        if (!class_exists($className))
            return;

        $seeder = App::make($className);
        $seeder->run();

        $this->note(sprintf('<info>Seeded %s</info> ', $module));
        return $this;
    }

    /**
     * Downloads the core from the update server.
     * @param string $hash Expected file hash.
     * @return void
     */
    public function downloadCore($hash)
    {
        $this->requestServerFile('core/get', 'core', $hash, ['type' => 'update']);
    }

    /**
     * Extracts the core after it has been downloaded.
     * @param string $hash
     * @param string $build
     * @return void
     */
    public function extractCore($hash, $build)
    {
        $filePath = $this->getFilePath('core');

        if (!Zip::extract($filePath, $this->baseDirectory))
            throw new ApplicationException(Lang::get('system::lang.zip.extract_failed', ['file' => $filePath]));

        @unlink($filePath);

        Parameters::set([
            'system::core.hash'  => $hash,
            'system::core.build' => $build
        ]);
    }

    //
    // Plugins
    //

    /**
     * Looks up a plugin from the update server.
     * @param string $name Plugin name.
     * @return array Details about the plugin.
     */
    public function requestPluginDetails($name)
    {
        $result = $this->requestServerData('plugin/detail', ['name' => $name]);
        return $result;
    }

    /**
     * Runs update on a single plugin
     * @param string $name Plugin name.
     * @return self
     */
    public function updatePlugin($name)
    {
        /*
         * Update the plugin database and version
         */
        if (!($plugin = $this->pluginManager->findByIdentifier($name))) {
            $this->note('<error>Unable to find:</error> ' . $name);
            return;
        }

        $this->versionManager->resetNotes();
        if ($this->versionManager->updatePlugin($plugin) !== false) {
            $this->note($name);
            foreach ($this->versionManager->getNotes() as $note) {
                $this->note(' - '.$note);
            }
        }
        return $this;
    }

    /**
     * Removes an existing plugin
     * @param string $name Plugin name.
     * @return self
     */
    public function rollbackPlugin($name)
    {
        /*
         * Remove the plugin database and version
         */
        if (!($plugin = $this->pluginManager->findByIdentifier($name))) {
            $this->note('<error>Unable to find:</error> ' . $name);
            return;
        }

        if ($this->versionManager->removePlugin($plugin))
            $this->note('<info>Rolled back:</info> ' . $name);

        return $this;
    }

    /**
     * Downloads a plugin from the update server.
     * @param string $name Plugin name.
     * @param string $hash Expected file hash.
     * @return self
     */
    public function downloadPlugin($name, $hash)
    {
        $fileCode = $name . $hash;
        $this->requestServerFile('plugin/get', $fileCode, $hash, ['name' => $name]);
    }

    /**
     * Extracts a plugin after it has been downloaded.
     */
    public function extractPlugin($name, $hash)
    {
        $fileCode = $name . $hash;
        $filePath = $this->getFilePath($fileCode);

        if (!Zip::extract($filePath, $this->baseDirectory . '/plugins/'))
            throw new ApplicationException(Lang::get('system::lang.zip.extract_failed', ['file' => $filePath]));

        @unlink($filePath);
    }

    //
    // Notes
    //

    /**
     * Raise a note event for the migrator.
     * @param  string  $message
     * @return void
     */
    protected function note($message)
    {
        $this->notes[] = $message;
        return $this;
    }

    /**
     * Get the notes for the last operation.
     * @return array
     */
    public function getNotes()
    {
        return $this->notes;
    }

    /**
     * Resets the notes store.
     * @return array
     */
    public function resetNotes()
    {
        $this->notes = [];
        return $this;
    }

    //
    // Gateway access
    //

    /**
     * Contacts the update server for a response.
     * @param  string $uri      Gateway API URI
     * @param  array  $postData Extra post data
     * @return array
     */
    public function requestServerData($uri, $postData = [])
    {
        $result = Http::post($this->createServerUrl($uri), function($http) use ($postData) {
            $this->applyHttpAttributes($http, $postData);
        });

        if ($result->code == 404)
            throw new ApplicationException(Lang::get('system::lang.server.response_not_found'));

        if ($result->code != 200) {
            throw new ApplicationException(
                strlen($result->body)
                ? $result->body
                : Lang::get('system::lang.server.response_empty')
            );
        }

        $resultData = false;

        try {
            $resultData = @json_decode($result->body, true);
        }
        catch (Exception $ex) {
            throw new ApplicationException(Lang::get('system::lang.server.response_invalid'));
        }

        if ($resultData === false || (is_string($resultData) && !strlen($resultData)))
            throw new ApplicationException(Lang::get('system::lang.server.response_invalid'));

        return $resultData;
    }

    /**
     * Downloads a file from the update server.
     * @param  string $uri          Gateway API URI
     * @param  string $fileCode     A unique code for saving the file.
     * @param  string $expectedHash The expected file hash of the file.
     * @param  array  $postData     Extra post data
     * @return void
     */
    public function requestServerFile($uri, $fileCode, $expectedHash, $postData = [])
    {
        $filePath = $this->getFilePath($fileCode);

        $result = Http::post($this->createServerUrl($uri), function($http) use ($postData, $filePath) {
            $this->applyHttpAttributes($http, $postData);
            $http->toFile($filePath);
        });

        if ($result->code != 200)
            throw new ApplicationException(Lang::get('system::lang.server.file_error'));

        if (md5_file($filePath) != $expectedHash) {
            @unlink($filePath);
            throw new ApplicationException(Lang::get('system::lang.server.file_corrupt'));
        }
    }

    /**
     * Calculates a file path for a file code
     * @param  string $fileCode A unique file code
     * @return string           Full path on the disk
     */
    private function getFilePath($fileCode)
    {
        $name = md5($fileCode) . '.arc';
        return $this->tempDirectory . '/' . $name;
    }

    /**
     * Set the API security for all transmissions.
     * @param string $key    API Key
     * @param string $secret API Secret
     */
    public function setSecurity($key, $secret)
    {
        $this->key = $key;
        $this->secret = $secret;
    }

    /**
     * Create a complete gateway server URL from supplied URI
     * @param  string $uri URI
     * @return string      URL
     */
    private function createServerUrl($uri)
    {
        $gateway = Config::get('cms.updateServer', 'http://octobercms.com/api');
        if (substr($gateway, -1) != '/')
            $gateway .= '/';

        return $gateway . $uri;
    }

    /**
     * Modifies the Network HTTP object with common attributes.
     * @param  Http $http      Network object
     * @param  array $postData Post data
     * @return void
     */
    private function applyHttpAttributes($http, $postData)
    {
        $postData['url'] = base64_encode(URL::to('/'));

        if ($this->key && $this->secret) {
            $postData['nonce'] = $this->createNonce();
            $http->header('Rest-Key', $this->key);
            $http->header('Rest-Sign', $this->createSignature($postData, $this->secret));
        }

        if ($credentials = Config::get('cms.updateAuth'))
            $http->auth($credentials);

        $http->noRedirect();
        $http->data($postData);
    }

    /**
     * Create a nonce based on millisecond time
     * @return int
     */
    private function createNonce()
    {
        $mt = explode(' ', microtime());
        return $mt[1] . substr($mt[0], 2, 6);
    }

    /**
     * Create a unique signature for transmission.
     * @return string
     */
    private function createSignature($data, $secret)
    {
        return base64_encode(hash_hmac('sha512', http_build_query($data, '', '&'), base64_decode($secret), true));
    }

}
