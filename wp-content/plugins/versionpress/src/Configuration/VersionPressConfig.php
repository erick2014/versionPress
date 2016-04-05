<?php

namespace VersionPress\Configuration;

use Nette\Neon\Neon;

class VersionPressConfig {

    public $defaults = array(
        'gui' => 'javascript',
        'requireApiAuth' => true
    );

    public $customConfig = array();
    public $mergedConfig = array();

    public $gitBinary;

    function __construct() {

        $defaultsFile = VERSIONPRESS_PLUGIN_DIR . '/vpconfig.defaults.neon';
        $customConfigFile = VERSIONPRESS_PLUGIN_DIR . '/vpconfig.neon';

        $this->defaults = array_merge($this->defaults, Neon::decode(file_get_contents($defaultsFile)));

        if (file_exists($customConfigFile)) {
            $this->customConfig = Neon::decode(file_get_contents($customConfigFile));
            if ($this->customConfig === null) {
                $this->customConfig = array();
            }
        }

        $this->mergedConfig = array_merge($this->defaults, $this->customConfig);

        $this->gitBinary = $this->mergedConfig['git-binary'];

    }

}
