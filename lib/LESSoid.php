<?php
/**
 *  RESToid shim based around MediaWiki's calling of Less_Parser
 */
class Less_Parser {
	public static $default_options = [
		'compress'				=> false,			// option - whether to compress
		'strictUnits'			=> false,			// whether units need to evaluate correctly
		'strictMath'			=> false,			// whether math has to be within parenthesis
		'relativeUrls'			=> true,			// option - whether to adjust URL's to be relative
		'urlArgs'				=> '',				// whether to add args into url tokens
		'numPrecision'			=> 8,
		'import_dirs'			=> [],
		'import_callback'		=> null,
		'cache_dir'				=> null,
		'cache_method'			=> 'php', 			// false, 'serialize', 'php', 'var_export', 'callback';
		'cache_callback_get'	=> null,
		'cache_callback_set'	=> null,
		'sourceMap'				=> false,			// whether to output a source map
		'sourceMapBasepath'		=> null,
		'sourceMapWriteTo'		=> null,
		'sourceMapURL'			=> null,
		'indentation' 			=> '  ',
		'plugins'				=> [],
	];

	public static $options = [];

	public $cssBuffer = "";

	public $preBuffer = "";

	public $config;

	/**
	 * Setup Constructor
	 */
	public function __construct() {
		$this->SetOptions(self::$default_options);
		$this->Reset(null);

		$config = parse_ini_file(__DIR__ . '/config.defaults.ini');
		if (file_exists(__DIR__ . "/config.ini")) {
			$overrides = parse_ini_file(__DIR__ . '/config.ini');
			$config = array_replace($config, $overrides);
		}
		$this->config = $config;
	}

	public function getConfig($key) {
		if (isset($this->config[$key])) {
			return $this->config[$key];
		} else {
			return false;
		}
	}

	/**
	 * Reset Options to Default
	 *
	 * @param array|null $options
	 */
	public function Reset($options = null) {
		$this->rules = [];

		if (is_array($options)) {
			$this->SetOptions(self::$default_options);
			$this->SetOptions($options);
		}
	}

	/**
	 * Use REST call to LESSoid to parse less.
	 *
	 * @access public
	 * @param string $str
	 * @param string $uriRoot
	 * @return array
	 */
	public function parseCallREST($str, $uriRoot = '') {
		$lessoidServer = "http://localhost";

		$request = [
			"options" => [],
			"less" => $str,
			"key" => base64_encode($str)
		];

		if (isset(self::$options['import_dirs']) && is_array(self::$options['import_dirs'])) {
			$request['options']['paths'] = array_reverse(array_keys(self::$options['import_dirs']));
		}

		$request = http_build_query($request);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_URL, $lessoidServer . ":8099/parse");
		curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
		$return = curl_exec($ch);
		curl_close($ch);

		$json = json_decode($return, 1);
		if ($json) {
			return $json;
		} else {
			return ["message" => "Response was not JSON", "response" => $return];
		}
	}

	/**
	 * Use the CLI to parse less.
	 *
	 * @access public
	 * @param string $str
	 * @param string $uriRoot
	 * @return array
	 */
	public function parseCLI($str, $uriRoot = '') {
		$cliPath = realpath(__DIR__ . "/../services/lessoid/less-hydra/bin/");
		$exec = $cliPath . "/lessc";

		if (!file_exists($exec)) {
			return ["message" => "Couldn't find lessc at $exec"];
		}

		if (!is_executable($exec)) {
			return ["message" => "Lessc exists, but is not executable. Please fix your permissions."];
		}
		$exec .= ' --no-color';

		if (isset(self::$options['import_dirs']) && is_array(self::$options['import_dirs'])) {
			$paths = implode(":", array_reverse(array_keys(self::$options['import_dirs'])));
			$exec .= " --include-path=$paths";
		}

		$exec .= " -";

		if (!empty($this->getConfig('node_override'))) {
			if (is_executable($this->getConfig('node_override'))) {
				$nodeBin = $this->getConfig('node_override');
			} else {
				return ["message" => "Your configuration for node_override (" . $this->getConfig('node_override') . ") is not executable."];
			}
		} else {
			$nodeBin = 'node';
		}
		$exec = $nodeBin . " " . $exec;

		$descriptorspec = [
		   0 => ["pipe", "r"],  // stdin is a pipe that the child will read from
		   1 => ["pipe", "w"],  // stdout is a pipe that the child will write to
		   2 => ["pipe", "w"]
		];

		$pipes = [];
		$process = proc_open($exec, $descriptorspec, $pipes, $uriRoot, ['PATH' => '/usr/bin/:/usr/local/bin/']);
		if (is_resource($process)) {
			/* write LESS */
			fwrite($pipes[0], $str);
			fclose($pipes[0]);

			/* read compiled css */
			$css = stream_get_contents($pipes[1]);
			fclose($pipes[1]);

			/* check for errors */
			$stderr = stream_get_contents($pipes[2]);
			if (!empty($stderr)) {
				return ["message" => $stderr];
			}
			fclose($pipes[2]);
			proc_close($process);

			if (!strlen($css)) {
				return ["message" => "No CSS Returned from lessc."];
			}

			return ["css" => $css];
		} else {
			return ["message" => "Failed to start CLI lessc"];
		}
	}

	/**
	 * Parse a LESS string to CSS
	 *
	 * @param string $str
	 * @param string $fileUri
	 * @return string
	 */
	public function parse($str, $fileUri = '') {
		// Default handling that was already hear...
		// gonna just leave it?
		if (!$fileUri) {
			$uriRoot = '';
			$filename = 'anonymous-file-' . self::$next_id++ . '.less';
		} else {
			$fileUri = self::WinPath($fileUri);
			$filename = $fileUri;
			$uriRoot = dirname($fileUri);
		}
		$uriRoot = self::WinPath($uriRoot);

		/* Lets ittorate and find any sub directories to check in the import path. */
		if (isset(self::$options['import_dirs']) && is_array(self::$options['import_dirs'])) {
			foreach (self::$options['import_dirs'] as $path => $v) {
				$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);
				foreach ($objects as $name => $object) {
					if ($object->isDir() && !in_array(basename($name), ['.','..'])) {
						self::$options['import_dirs'][$name . "/"] = "";
					}
				}
			}
		}
		if (isset($uriRoot)) {
			// add it to the import path too... because apparently looking right next to you is too hard, less.js...
			self::$options['import_dirs'][$uriRoot] = "";
		}

		// Prepend string with preBuffer, for use with ModifyVars, ect.
		$str = $this->preBuffer . "\n\n" . $str;

		$time_start = microtime(true);
		$this->cssBuffer = "/* === Start " . basename($filename) . " === */\n";

		// Attempt RESTfull decoding.
		$parsed = $this->parseCallREST($str, $uriRoot);

		if (!isset($parsed['css'])) {
			// we didn't get CSS back.
			$errmsg = isset($parsed['message']) ? $parsed['message'] : "Unknown Error. " . json_encode($parsed) . "";
			$this->cssBuffer .= "\n/* No CSS Returned from REST Decoding! \n $errmsg */\n";

			// Lets fallback to the CLI, the service may be down.
			$parsedCli = $this->parseCLI($str, $uriRoot);
			if (!isset($parsedCli['css'])) {
				$errmsg = isset($parsedCli['message']) ? $parsedCli['message'] : "Unknown Error " . json_encode($parsedCli) . "";
				$this->cssBuffer .= "\n/* No CSS Returned from CLI Decoding! \n $errmsg */\n";
			} else {
				// Add parsed CSS to the buffer.
				$this->cssBuffer .= $parsedCli['css'];
			}
		} else {
			// Add parsed CSS to the buffer.
			$this->cssBuffer .= $parsed['css'];
		}

		$time_end = microtime(true);
		$this->cssBuffer .= "\n/* === End " . basename($filename) . " (compiled in " . round($time_end - $time_start, 2) . " seconds.) === */";
		return $this;
	}

	/**
	 * Parse a Less string from a given file
	 *
	 * @param string $filename The file to parse
	 * @param string $uriRoot The url of the file
	 * @param bool $returnRoot Indicates whether the return value should be a css string a root node
	 * @return Less_Parser
	 */
	public function parseFile($filename, $uriRoot = '', $returnRoot = false) {
		// All this stuff was happening in the original.
		// Maybe it should still happen in ours?
		if (!file_exists($filename)) {
			throw new Exception(sprintf('File `%s` not found.', $filename));
		}

		if (!$returnRoot && !empty($uriRoot) && basename($uriRoot) == basename($filename)) {
			$uriRoot = dirname($uriRoot);
		}

		$filename = $filename ? self::WinPath(realpath($filename)) : false;
		$uriRoot = $uriRoot ? self::WinPath($uriRoot) : '';

		$fileContent = file_get_contents($filename);

		$this->parse($fileContent, $filename);
		return $this;
	}

	/**
	 * Get the CSS buffer
	 * @return string $cssBuffer
	 */
	public function getCss() {
		return $this->cssBuffer;
	}

	/**
	 * Allows a user to set variables values
	 * @param array $vars
	 * @return Less_Parser
	 */
	public function ModifyVars($vars) {
		$s = '';
		foreach($vars as $name => $value) {
			$s .= (($name[0] === '@') ? '' : '@') . $name .': '. $value . ((substr($value,-1) === ';') ? '' : ';');
		}
		$this->preBuffer = $s;
		return $this;
	}

	/**
	 * Fix paths for Windows.
	 *
	 * @param type $path Fixed path.
	 */
	public static function WinPath($path) {
		return str_replace('\\', '/', $path);
	}

	/**
	 * Set a list of directories or callbacks the parser should use for determining import paths
	 *
	 * @param array $dirs
	 */
	public function SetImportDirs($dirs) {
		self::$options['import_dirs'] = [];
		foreach ($dirs as $path => $uriRoot) {
			$path = self::WinPath($path);
			if (!empty($path)) {
				$path = rtrim($path, '/') . '/';
			}
			if (!is_callable($uriRoot)) {
				$uriRoot = self::WinPath($uriRoot);
				if (!empty($uriRoot)) {
					$uriRoot = rtrim($uriRoot, '/') . '/';
				}
			}
			self::$options['import_dirs'][$path] = $uriRoot;
		}
	}

	/**
	 * Set Multiple Options
	 *
	 * @param array $options
	 */
	public function SetOptions($options) {
		foreach ($options as $option => $value) {
			$this->SetOption($option, $value);
		}
	}

	/**
	 * Set Option
	 *
	 * @param string $option
	 * @param string $value
	 */
	public function SetOption($option, $value) {
		switch ($option) {
			case 'import_dirs':
				$this->SetImportDirs($value);
			return;
		}
		self::$options[$option] = $value;
	}

	/**
	 * Shim to feed back empty array.
	 * No actual idea what mediawiki is doing with this file list, but it doesn't need it
	 * as far as im concerned. Tho when code expects arrays, give it arrays.
	 *
	 * @return array [an empty one!]
	 */
	public function AllParsedFiles() {
		return [];
	}

	/**
	 * Catch any calls to this class that are not implemented
	 *
	 * @param mixed $name call name
	 * @param mixed $arguments call arguments
	 * @return nothing
	 */
	public function __call($name, $arguments) {
	}

	/**
	 * Catch any static calls to this class that are not implemented
	 *
	 * @param mixed $name call name
	 * @param mixed $arguments call arguments
	 * @return nothing
	 */
	public static function __callStatic($name, $arguments) {
	}
}
