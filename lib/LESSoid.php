<?php
/**
 *  RESToid shim based around MediaWiki's calling of Less_Parser
 */
class Less_Parser{

	public static $default_options = array(
		'compress'				=> false,			// option - whether to compress
		'strictUnits'			=> false,			// whether units need to evaluate correctly
		'strictMath'			=> false,			// whether math has to be within parenthesis
		'relativeUrls'			=> true,			// option - whether to adjust URL's to be relative
		'urlArgs'				=> '',				// whether to add args into url tokens
		'numPrecision'			=> 8,
		'import_dirs'			=> array(),
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
		'plugins'				=> array(),
	);
	public static $options = array();
	private $env; // this handles some defaults or something...
	public $cssBuffer = "";
	public $input; // not using it, fam.
	/*
		Basic Setup Stuff that MEdiaWiki is calling and we need to leave in place.
	 */

	public function __construct( $env = null ){
		if( $env instanceof Less_Environment ){
			$this->env = $env;
		}else{
			$this->SetOptions(Less_Parser::$default_options);
			$this->Reset( $env );
		}
	}

	public function Reset( $options = null ){
		$this->rules = array();
		$this->env = new Less_Environment($options);
		$this->env->Init();
		//set new options
		if( is_array($options) ){
			$this->SetOptions(Less_Parser::$default_options);
			$this->SetOptions($options);
		}
	}

	public function parseCallREST($str, $uriRoot) {


		$request = [
			"options" => [],
			"less" => $str,
			"key" => base64_encode($str)
		];

		if (isset(self::$options['import_dirs']) && is_array(self::$options['import_dirs'])) {
			$request['options']['paths'] = array_reverse(array_keys(self::$options['import_dirs']));
		}

		if (!empty($uriRoot)) {
			$request['options']['rootpath'] = $uriRoot."/";
		}

		$request = http_build_query($request);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	    curl_setopt($ch, CURLOPT_POST, 1);
	    curl_setopt($ch, CURLOPT_URL, $wgServer.":8099/parse");
	    curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
		$return = curl_exec($ch);
		curl_close($ch);

		$json = json_decode($return,1);
		if ($json) {
			return $json;
		} else {
			return ["message" => "Response was not JSON"];
		}


	}

	/**
	 * [parseCLI description]
	 * @param  [type] $str     [description]
	 * @param  [type] $uriRoot [description]
	 * @return [type]          [description]
	 */
	public function parseCLI($str, $uriRoot) {




		$cliPath = realpath(__DIR__."/../services/lessoid/less-hydra/bin/");
		$exec = $cliPath."lessc";

		if (isset(self::$options['import_dirs']) && is_array(self::$options['import_dirs'])) {
			$paths = implode(":",array_reverse(array_keys(self::$options['import_dirs'])));
			$exec .= " --include-path=$paths";
		}

		if (!empty($uriRoot)) {
			$exec .= " --rootpath=$uriRoot/";
		}

		$exec .= " -";

		$descriptorspec = array(
		   0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
		   1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
		   2 => array("pipe", "w"),
		);

		$process = proc_open($exec, $descriptorspec, $pipes, $uriRoot, array( 'PATH' => '/usr/local/bin/' ));
		if (is_resource($process)) {

			/* write LESS */
			fwrite( $pipes[0], $str);
			fclose( $pipes[0] );

			/* read compiled css */
			$css = stream_get_contents( $pipes[1] );
			fclose( $pipes[1] );

			/* check for errors */
			if( $stderr = stream_get_contents( $pipes[2] ) ) {
			    return ["message"=>$stderr];
			}
			fclose( $pipes[2] );
			proc_close( $process );

			if (!strlen($css)) {
				return ["message"=>"No CSS Returned from lessc."];
			}

			return ["css"=>$css];
		} else {
			return ["message"=>"Failed to start CLI lessc"];
		}

		return $exec;
	}

	/**
	 * [parse description]
	 * @param  [type] $str      [description]
	 * @param  string $fileUri [description]
	 * @return [type]           [description]
	 */
	public function parse( $str, $fileUri = '' ){
		// Default handling that was already hear...
		// gonna just leave it?
		if( !$fileUri ){
			$uriRoot = '';
			$filename = 'anonymous-file-'.Less_Parser::$next_id++.'.less';
		}else{
			$fileUri = self::WinPath($fileUri);
			$filename = $fileUri;
			$uriRoot = dirname($fileUri);
		}
		$uriRoot = self::WinPath($uriRoot);

		/* Lets ittorate and find any sub directories to check in the import path. */
		if (isset(self::$options['import_dirs']) && is_array(self::$options['import_dirs'])) {
			foreach (self::$options['import_dirs'] as $path => $v) {
				$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);
				foreach($objects as $name => $object){
					if ($object->isDir() && !in_array(basename($name), ['.','..'])) {
				    	self::$options['import_dirs'][$name."/"] = "";
					}
				}
			}
		}
		if (isset($uriRoot)) {
			// add it too the import path too... because apparently looking right next to you is too hard, less.js...
			// Or maybe rootpath doesn't work?
			self::$options['import_dirs'][$uriRoot] = "";
		}

		$time_start = microtime(true);
		$this->cssBuffer = "/* === Start ".basename($filename)." === */\n";

		// Attempt RESTfull decoding.
		$parsed = $this->parseCallREST($str, $uriRoot);

		if (!isset($parsed['css'])) {
			// we didn't get CSS back.
			$errmsg = isset($parsed['message']) ? $parsed['message'] : "Unknown Error";
			$this->cssBuffer .= "\n/* No CSS Returned from REST Decoding! \n $errmsg */\n";

			// Lets fallback to the CLI, the service may be down.
			$parsedCli = $this->parseCLI($str, $uriRoot);
			if (!isset($parsedCli['css'])) {
				$errmsg = isset($parsedCli['message']) ? $parsedCli['message'] : "Unknown Error";
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
		$this->cssBuffer .= "\n/* === End ".basename($filename)." (compiled in ".round($time_end - $time_start,2)." seconds.) === */";
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
	public function parseFile( $filename, $uriRoot = '', $returnRoot = false){
		// All this stuff was happening in the original.
		// Maybe it should still happen in ours?
		if( !file_exists($filename) ){
			$this->Error(sprintf('File `%s` not found.', $filename));
		}

		if( !$returnRoot && !empty($uriRoot) && basename($uriRoot) == basename($filename) ){
			$uriRoot = dirname($uriRoot);
		}

		$filename = $filename ? self::WinPath(realpath($filename)) : false;
		$uriRoot = $uriRoot ? self::WinPath($uriRoot) : '';

		$fileContent = file_get_contents($filename);

		$this->parse($fileContent, $filename);
		return $this;
	}



	public function getCss() {
		return $this->cssBuffer;
	}


	/*
		Here's some more stuff MediaWiki is gonna call... and may end up being
	 */
	static function AllParsedFiles(){
		return [];
	}

	/**
	 * Allows a user to set variables values
	 * @param array $vars
	 * @return Less_Parser
	 */
	public function ModifyVars( $vars ){
		$s = '';
		foreach($vars as $name => $value){
			$s .= (($name[0] === '@') ? '' : '@') . $name .': '. $value . ((substr($value,-1) === ';') ? '' : ';');
		}
		$this->input = $s;
		return $this;
	}

	public static function WinPath($path){
		return str_replace('\\', '/', $path);
	}

	/**
	 * Set a list of directories or callbacks the parser should use for determining import paths
	 *
	 * @param array $dirs
	 */
	public function SetImportDirs( $dirs ){
		Less_Parser::$options['import_dirs'] = array();
		foreach($dirs as $path => $uriRoot){
			$path = self::WinPath($path);
			if( !empty($path) ){
				$path = rtrim($path,'/').'/';
			}
			if ( !is_callable($uriRoot) ){
				$uriRoot = self::WinPath($uriRoot);
				if( !empty($uriRoot) ){
					$uriRoot = rtrim($uriRoot,'/').'/';
				}
			}
			Less_Parser::$options['import_dirs'][$path] = $uriRoot;
		}
	}

	public function SetOptions( $options ){
		foreach($options as $option => $value){
			$this->SetOption($option,$value);
		}
	}

	public function SetOption($option,$value){
		switch($option){
			case 'import_dirs':
				$this->SetImportDirs($value);
			return;
			case 'cache_dir':
				if( is_string($value) ){
					Less_Cache::SetCacheDir($value);
					Less_Cache::CheckCacheDir();
				}
			return;
		}
		Less_Parser::$options[$option] = $value;
	}

	public function SetCacheDir( $dir ){

		if( !file_exists($dir) ){
			if( mkdir($dir) ){
				return true;
			}
			throw new Less_Exception_Parser('Less.php cache directory couldn\'t be created: '.$dir);
		}elseif( !is_dir($dir) ){
			throw new Less_Exception_Parser('Less.php cache directory doesn\'t exist: '.$dir);
		}elseif( !is_writable($dir) ){
			throw new Less_Exception_Parser('Less.php cache directory isn\'t writable: '.$dir);
		}else{
			$dir = self::WinPath($dir);
			Less_Cache::$cache_dir = rtrim($dir,'/').'/';
			Less_Parser::$options['cache_dir'] = Less_Cache::$cache_dir;
			return true;
		}
	}
	public function Error($msg){
		throw new Less_Exception_Parser($msg, null, $this->furthest, $this->env->currentFileInfo);
	}
}

/**
 * Environment
 *
 * @package Less
 * @subpackage environment
 */
class Less_Environment{

	//public $paths = array();				// option - unmodified - paths to search for imports on
	//public static $files = array();		// list of files that have been imported, used for import-once
	//public $rootpath;						// option - rootpath to append to URL's
	//public static $strictImports = null;	// option -
	//public $insecure;						// option - whether to allow imports from insecure ssl hosts
	//public $processImports;				// option - whether to process imports. if false then imports will not be imported
	//public $javascriptEnabled;			// option - whether JavaScript is enabled. if undefined, defaults to true
	//public $useFileCache;					// browser only - whether to use the per file session cache
	public $currentFileInfo;				// information about the current file - for error reporting and importing and making urls relative etc.

	public $importMultiple = false; 		// whether we are currently importing multiple copies


	/**
	 * @var array
	 */
	public $frames = array();

	/**
	 * @var array
	 */
	public $mediaBlocks = array();

	/**
	 * @var array
	 */
	public $mediaPath = array();

	public static $parensStack = 0;

	public static $tabLevel = 0;

	public static $lastRule = false;

	public static $_outputMap;

	public static $mixin_stack = 0;

	/**
	 * @var array
	 */
	public $functions = array();


	public function Init(){

		self::$parensStack = 0;
		self::$tabLevel = 0;
		self::$lastRule = false;
		self::$mixin_stack = 0;

		if( Less_Parser::$options['compress'] ){

			Less_Environment::$_outputMap = array(
				','	=> ',',
				': ' => ':',
				''  => '',
				' ' => ' ',
				':' => ' :',
				'+' => '+',
				'~' => '~',
				'>' => '>',
				'|' => '|',
		        '^' => '^',
		        '^^' => '^^'
			);

		}else{

			Less_Environment::$_outputMap = array(
				','	=> ', ',
				': ' => ': ',
				''  => '',
				' ' => ' ',
				':' => ' :',
				'+' => ' + ',
				'~' => ' ~ ',
				'>' => ' > ',
				'|' => '|',
		        '^' => ' ^ ',
		        '^^' => ' ^^ '
			);

		}
	}


	public function copyEvalEnv($frames = array() ){
		$new_env = new Less_Environment();
		$new_env->frames = $frames;
		return $new_env;
	}


	public static function isMathOn(){
		return !Less_Parser::$options['strictMath'] || Less_Environment::$parensStack;
	}

	public static function isPathRelative($path){
		return !preg_match('/^(?:[a-z-]+:|\/)/',$path);
	}


	/**
	 * Canonicalize a path by resolving references to '/./', '/../'
	 * Does not remove leading "../"
	 * @param string path or url
	 * @return string Canonicalized path
	 *
	 */
	public static function normalizePath($path){

		$segments = explode('/',$path);
		$segments = array_reverse($segments);

		$path = array();
		$path_len = 0;

		while( $segments ){
			$segment = array_pop($segments);
			switch( $segment ) {

				case '.':
				break;

				case '..':
					if( !$path_len || ( $path[$path_len-1] === '..') ){
						$path[] = $segment;
						$path_len++;
					}else{
						array_pop($path);
						$path_len--;
					}
				break;

				default:
					$path[] = $segment;
					$path_len++;
				break;
			}
		}

		return implode('/',$path);
	}


	public function unshiftFrame($frame){
		array_unshift($this->frames, $frame);
	}

	public function shiftFrame(){
		return array_shift($this->frames);
	}

}

/**
 * Utility for handling the generation and caching of css files
 *
 * @package Less
 * @subpackage cache
 *
 */
class Less_Cache{

	// directory less.php can use for storing data
	public static $cache_dir	= false;

	// prefix for the storing data
	public static $prefix		= 'lessphp_';

	// prefix for the storing vars
	public static $prefix_vars	= 'lessphpvars_';

	// specifies the number of seconds after which data created by less.php will be seen as 'garbage' and potentially cleaned up
	public static $gc_lifetime	= 604800;


	/**
	 * Save and reuse the results of compiled less files.
	 * The first call to Get() will generate css and save it.
	 * Subsequent calls to Get() with the same arguments will return the same css filename
	 *
	 * @param array $less_files Array of .less files to compile
	 * @param array $parser_options Array of compiler options
	 * @param array $modify_vars Array of variables
	 * @return string Name of the css file
	 */
	public static function Get( $less_files, $parser_options = array(), $modify_vars = array() ){


		//check $cache_dir
		if( isset($parser_options['cache_dir']) ){
			Less_Cache::$cache_dir = $parser_options['cache_dir'];
		}

		if( empty(Less_Cache::$cache_dir) ){
			throw new Exception('cache_dir not set');
		}

		if( isset($parser_options['prefix']) ){
			Less_Cache::$prefix = $parser_options['prefix'];
		}

		if( empty(Less_Cache::$prefix) ){
			throw new Exception('prefix not set');
		}

		if( isset($parser_options['prefix_vars']) ){
			Less_Cache::$prefix_vars = $parser_options['prefix_vars'];
		}

		if( empty(Less_Cache::$prefix_vars) ){
			throw new Exception('prefix_vars not set');
		}

		self::CheckCacheDir();
		$less_files = (array)$less_files;


		//create a file for variables
		if( !empty($modify_vars) ){
			$lessvars = Less_Parser::serializeVars($modify_vars);
			$vars_file = Less_Cache::$cache_dir . Less_Cache::$prefix_vars . sha1($lessvars) . '.less';

			if( !file_exists($vars_file) ){
				file_put_contents($vars_file, $lessvars);
			}

			$less_files += array($vars_file => '/');
		}


		// generate name for compiled css file
		$hash = md5(json_encode($less_files));
 		$list_file = Less_Cache::$cache_dir . Less_Cache::$prefix . $hash . '.list';

 		// check cached content
 		if( !isset($parser_options['use_cache']) || $parser_options['use_cache'] === true ){
			if( file_exists($list_file) ){

				self::ListFiles($list_file, $list, $cached_name);
				$compiled_name = self::CompiledName($list, $hash);

				// if $cached_name is the same as the $compiled name, don't regenerate
				if( !$cached_name || $cached_name === $compiled_name ){

					$output_file = self::OutputFile($compiled_name, $parser_options );

					if( $output_file && file_exists($output_file) ){
						@touch($list_file);
						return basename($output_file); // for backwards compatibility, we just return the name of the file
					}
				}
			}
		}

		$compiled = self::Cache( $less_files, $parser_options );
		if( !$compiled ){
			return false;
		}

		$compiled_name = self::CompiledName( $less_files, $hash );
		$output_file = self::OutputFile($compiled_name, $parser_options );


		//save the file list
		$list = $less_files;
		$list[] = $compiled_name;
		$cache = implode("\n",$list);
		file_put_contents( $list_file, $cache );


		//save the css
		file_put_contents( $output_file, $compiled );


		//clean up
		//self::CleanCache();

		return basename($output_file);
	}

	/**
	 * Force the compiler to regenerate the cached css file
	 *
	 * @param array $less_files Array of .less files to compile
	 * @param array $parser_options Array of compiler options
	 * @param array $modify_vars Array of variables
	 * @return string Name of the css file
	 */
	public static function Regen( $less_files, $parser_options = array(), $modify_vars = array() ){
		$parser_options['use_cache'] = false;
		return self::Get( $less_files, $parser_options, $modify_vars );
	}

	public static function Cache( &$less_files, $parser_options = array() ){


		// get less.php if it exists
		$file = dirname(__FILE__) . '/Less.php';
		if( file_exists($file) && !class_exists('Less_Parser') ){
			require_once($file);
		}

		$parser_options['cache_dir'] = Less_Cache::$cache_dir;
		$parser = new Less_Parser($parser_options);


		// combine files
		foreach($less_files as $file_path => $uri_or_less ){

			//treat as less markup if there are newline characters
			if( strpos($uri_or_less,"\n") !== false ){
				$parser->Parse( $uri_or_less );
				continue;
			}

			$parser->ParseFile( $file_path, $uri_or_less );
		}

		$compiled = $parser->getCss();


		$less_files = $parser->allParsedFiles();

		return $compiled;
	}


	private static function OutputFile( $compiled_name, $parser_options ){

		//custom output file
		if( !empty($parser_options['output']) ){

			//relative to cache directory?
			if( preg_match('#[\\\\/]#',$parser_options['output']) ){
				return $parser_options['output'];
			}

			return Less_Cache::$cache_dir.$parser_options['output'];
		}

		return Less_Cache::$cache_dir.$compiled_name;
	}


	private static function CompiledName( $files, $extrahash ){

		//save the file list
		$temp = array(170);
		foreach($files as $file){
			$temp[] = filemtime($file)."\t".filesize($file)."\t".$file;
		}

		return Less_Cache::$prefix.sha1(json_encode($temp).$extrahash).'.css';
	}


	public static function SetCacheDir( $dir ){
		Less_Cache::$cache_dir = $dir;
	}

	public static function CheckCacheDir(){

		Less_Cache::$cache_dir = str_replace('\\','/',Less_Cache::$cache_dir);
		Less_Cache::$cache_dir = rtrim(Less_Cache::$cache_dir,'/').'/';

		if( !file_exists(Less_Cache::$cache_dir) ){
			if( !mkdir(Less_Cache::$cache_dir) ){
				throw new Less_Exception_Parser('Less.php cache directory couldn\'t be created: '.Less_Cache::$cache_dir);
			}

		}elseif( !is_dir(Less_Cache::$cache_dir) ){
			throw new Less_Exception_Parser('Less.php cache directory doesn\'t exist: '.Less_Cache::$cache_dir);

		}elseif( !is_writable(Less_Cache::$cache_dir) ){
			throw new Less_Exception_Parser('Less.php cache directory isn\'t writable: '.Less_Cache::$cache_dir);

		}

	}


	/**
	 * Delete unused less.php files
	 *
	 */
	public static function CleanCache(){
		static $clean = false;

		if( $clean ){
			return;
		}

		$files = scandir(Less_Cache::$cache_dir);
		if( $files ){
			$check_time = time() - self::$gc_lifetime;
			foreach($files as $file){

				// don't delete if the file wasn't created with less.php
				if( strpos($file,Less_Cache::$prefix) !== 0 ){
					continue;
				}

				$full_path = Less_Cache::$cache_dir . $file;

				// make sure the file still exists
				// css files may have already been deleted
				if( !file_exists($full_path) ){
					continue;
				}
				$mtime = filemtime($full_path);

				// don't delete if it's a relatively new file
				if( $mtime > $check_time ){
					continue;
				}

				$parts = explode('.',$file);
				$type = array_pop($parts);


				// delete css files based on the list files
				if( $type === 'css' ){
					continue;
				}


				// delete the list file and associated css file
				if( $type === 'list' ){
					self::ListFiles($full_path, $list, $css_file_name);
					if( $css_file_name ){
						$css_file = Less_Cache::$cache_dir . $css_file_name;
						if( file_exists($css_file) ){
							unlink($css_file);
						}
					}
				}

				unlink($full_path);
			}
		}

		$clean = true;
	}


	/**
	 * Get the list of less files and generated css file from a list file
	 *
	 */
	static function ListFiles($list_file, &$list, &$css_file_name ){

		$list = explode("\n",file_get_contents($list_file));

		//pop the cached name that should match $compiled_name
		$css_file_name = array_pop($list);

		if( !preg_match('/^' . Less_Cache::$prefix . '[a-f0-9]+\.css$/',$css_file_name) ){
			$list[] = $css_file_name;
			$css_file_name = false;
		}

	}

}

