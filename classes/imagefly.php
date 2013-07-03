<?php defined('SYSPATH') or die('No direct script access.');
/**
 * @package   Modules
 * @category  Imagefly
 * @author    Fady Khalife
 * @uses      Image Module
 *
 * Concept based on the smart-lencioni-image-resizer by Joe Lencioni
 * http://code.google.com/p/smart-lencioni-image-resizer/
 *
 * digi3studio.com
 * add -p parameter for portrait crop. 
 * when cropping image from portrait to landscape. Head of the photo usually cropped.
 * by adjust the crop position from center (1/2) to 1/4 of the image will be prevent such problem.
 * add -l parameter for landscape crop.
 */
 
class ImageFly
{
	/**
	 * @var  array       This modules config options
	 */
	protected $config = NULL;
	
	/**
	 * @var  string      Stores the path to the cache directory which is either whats set in the config "cache_dir"
	 *                   or processed sub directories when the "mimic_sourcestructure" config option id set to TRUE
	 */
	protected $cache_dir = NULL;
	
	/**
	 * @var  object      Kohana image instance
	 */
	protected $image = NULL;
	
	/**
	 * @var  boolean     A flag for weither we should serve the default or cached image
	 */
	protected $serve_default = FALSE;
	
	/**
	 * @var  string      The source filepath and filename
	 */
	protected $source_file = NULL;
	
	/**
	 * @var  array       Stores the URL params in the following format
	 *                   w = Width (int)
	 *                   h = Height (int)
	 *                   c = Crop (bool)
	 */
	protected $url_params = array();
	
	/**
	 * @var  string      Last modified Unix timestamp of the source file
	 */
	protected $source_modified = NULL;
	
	/**
	 * @var  string      The cached filename with path ($this->cache_dir)
	 */
	protected $cached_file = NULL;


	private $isServeTypeChanged = FALSE;
	private $serveExtension = '';
	/**
	 * Constructorbot
	 */
	public function __construct($params,$filepath,$ext)
	{
		// Prevent unnecessary warnings on servers that are set to display E_STRICT errors, these will damage the image data.
		error_reporting(error_reporting() & ~E_STRICT);

		//check filePath exist or fetch the png version;
		$this->serveExtension = $ext;
		//try file exist.
		//set source file
		//if not exist, fetch the png
		if (file_exists($filepath.'.'.$ext)) {
			$this->source_file = $filepath.'.'.$ext;
		} else {
			$this->isServeTypeChanged = TRUE;
			$this->source_file = $filepath.'.png';
		}


		// Set the config
		$this->config = Kohana::config('imagefly');
		
		// Try to create the cache directory if it does not exist
		$this->_createCacheDir();

		// Parse and set the image modify params
		$this->image = Image::factory($this->source_file);

		$this->_set_params($params);
		
		// Set the source file modified timestamp
		$this->source_modified = filemtime($this->source_file);
		
		// Try to create the mimic directory structure if required
		$this->_createMimicCacheDir();
		
		// Set the cached filepath with filename
		$this->cached_file = $this->cache_dir.$this->_encodedFilename();
		
		// Create a modified cache file or dont...
		if (! $this->_cachedExists() AND $this->_cachedRequired())
	   {
			$this->_createCached();
		}
		
		// Serve the image file
		$this->_serveFile();
	}
	
	/**
	 * Try to create the config cache dir if required
	 * Set $cache_dir
	 */
	private function _createCacheDir()
	{
		if( ! file_exists($this->config['cache_dir']))
		{
			try
			{
				mkdir($this->config['cache_dir'], 0755, TRUE);
			}
			catch(Exception $e)
			{
				throw new Kohana_Exception($e);
			}
		}
		
		// Set the cache dir
		$this->cache_dir = $this->config['cache_dir'];
	}
	
	/**
	 * Try to create the mimic cache dir from the source path if required
	 * Set $cache_dir
	 */
	private function _createMimicCacheDir()
	{
		if ($this->config['mimic_sourcestructure'])
		{
			// Get the dir from the source file
			$mimic_dir = $this->config['cache_dir'].pathinfo($this->source_file, PATHINFO_DIRNAME);
			
			// Try to create if it does not exist
			if( ! file_exists($mimic_dir))
			{
				try
				{
					mkdir($mimic_dir, 0755, TRUE);
				}
				catch(Exception $e)
				{
					throw new Kohana_Exception($e);
				}
			}
			
			// Set the cache dir, with trailling slash
			$this->cache_dir = $mimic_dir.'/';
		}
	}
	
	/**
	 * Sets the operations params from the url
	 * w = Width (int)
	 * h = Height (int)
	 * c = Crop (bool)
	 * p = Portrait Crop (crop from 30% top of the image, not center)
	 * l = Landscape Crop (crop from 0% left of the image)
	 */
	private function _set_params($params)
	{
		// Get values from request
//       $params = Request::$instance->param('params');
//       $filepath = Request::$instance->param('imagepath');
		
		// The parameters are separated by hyphens
		$raw_params	= explode('-', $params);
		
		// Set default param values
		$this->url_params['w'] = NULL;
		$this->url_params['h'] = NULL;
		$this->url_params['c'] = FALSE;
		$this->url_params['p'] = NULL;//portrait crop
		$this->url_params['l'] = NULL;//landscrape crop
		$this->url_params['r'] = NULL;//crop by rectangle
		
		// Update param values from passed values
		foreach ($raw_params as $raw_param)
		{
			$name = $raw_param[0];
			$value = substr($raw_param, 1, strlen($raw_param) - 1);
			if ($name == 'c')
			{
				$this->url_params[$name] = TRUE;
			}
			else
			{
				$this->url_params[$name] = $value;
			}
		}

		// Make width the height or vice versa if either is not passed
		if (empty($this->url_params['w']))
		{
			$this->url_params['w'] = $this->url_params['h'];
		}
		if (empty($this->url_params['h']))
		{
			$this->url_params['h'] = $this->url_params['w'];
		}
		
		// Must have at least a width or height
		if(empty($this->url_params['w']) AND empty($this->url_params['h']))
		{
			throw new Kohana_Exception('Invalid parameters, you must specify a width and/or height');
		}
	}
	
	/**
	 * Checks if a physical version of the cached image exists
	 * 
	 * @return boolean
	 */
	private function _cachedExists()
	{
		return file_exists($this->cached_file);
	}
	
	/**
	 * Checks that the param dimensions are are lower then current image dimensions
	 * 
	 * @return boolean
	 */
	private function _cachedRequired()
	{
		$image_info	= getimagesize($this->source_file);
		
		if (($this->url_params['w'] == $image_info[0]) AND ($this->url_params['h'] == $image_info[1]))
		{
			$this->serve_default = TRUE;
			return FALSE;
		}
		
		return TRUE;
	}
	
	/**
	 * Returns a hash of the filepath and params plus last modified of source to be used as a unique filename
	 * 
	 * @return  string
	 */
	private function _encodedFilename()
	{
        $ext = strtolower(pathinfo($this->source_file, PATHINFO_EXTENSION));
		$encode = md5($this->source_file.http_build_query($this->url_params));

		// Build the parts of the filename
		$encoded_name = $encode.'-'.$this->source_modified.'.'.$ext;

		return $encoded_name;
	}

	/**
	 * Creates a cached cropped/resized version of the file
	 */
	private function crop(Kohana_Image &$img, $width, $height){
		// Resize to highest width or height with overflow on the larger side
		$img->resize($width, $height, Image::INVERSE);
		// Crop any overflow from the larger side
		$img->crop($width, $height);
	}

	private function crop_portrait_adjustment(Kohana_Image &$img, $width, $height, $adjustment){
		// Resize to highest width or height with overflow on the larger side
		$img->resize($width, $height, Image::INVERSE);

		// if image is portrait, crop it depense on interpolation
		if($img->height > $img->width){
			$interpolate = '0.'.$adjustment;
			$offset_y = round(($img->height - $height)*$interpolate);

			$this->image->crop($width, $height,0,$offset_y);
		}else{
			// Crop any overflow from the larger side
			$this->image->crop($width, $height);
		}
	}

	private function crop_landscape_adjustment(Kohana_Image &$img, $width, $height, $adjustment){
		// Resize to highest width or height with overflow on the larger side
		$img->resize($width, $height, Image::INVERSE);

		// if image is portrait, crop it depense on interpolation
		if($img->height < $img->width){
			$interpolate = '0.'.$adjustment;
			$offset_x = round(($img->width - $width)*$interpolate);

			$this->image->crop($width, $height,$offset_x,0);
		}else{
			// Crop any overflow from the larger side
			$this->image->crop($width, $height);
		}
	}

	private function crop_rectangle(Kohana_Image &$img, $width, $height, $poi_x_percent, $poi_y_percent, $poi_distance_percent, $crop_adjust_x, $crop_adjust_y){
		//the crop start with center point of interest and distance
		$crop_x = round($img->width*$poi_x_percent);
		$crop_y = round($img->height*$poi_y_percent);
		$crop_size = round($img->width*$poi_distance_percent);

		$img->crop($crop_size, $crop_size,$crop_x,$crop_y);

		$img->resize($width, $height, Image::INVERSE);

		//it's square, no need to crop
		if($width==$height)return;

		// if image is portrait, crop it depense on interpolation
		if($height > $width){

			$offset_x = round(($img->width*$crop_adjust_x)-($width*0.5));
			//min limit
			if($offset_x<0)$offset_x=0;
			//max limit
			$max_x = $img->width-$width;
			if($offset_x>$max_x){
				$offset_x = $max_x;
			}
			$img->crop($width, $height,$offset_x,0);
		}else{
			$offset_y = round(($img->height*$crop_adjust_y)-($height*0.5));
			//min limit
			if($offset_y<0)$offset_y=0;
			//max limit
			$max_y = $img->height-$height;
			if($offset_y>$max_y){
				$offset_y = $max_y;
			}
			$img->crop($width, $height,0,$offset_y);
		}
	}

	private function _createCached()
	{
		if($this->url_params['c'])
		{
			$this->crop($this->image, $this->url_params['w'], $this->url_params['h']);
		}else if(!empty($this->url_params['p']))
		{
			$this->crop_portrait_adjustment($this->image,$this->url_params['w'],$this->url_params['h'],$this->url_params['p']);
		}
		else if(!empty($this->url_params['l'])){
			$this->crop_landscape_adjustment($this->image,$this->url_params['w'],$this->url_params['h'],$this->url_params['l']);
		}
		else if(!empty($this->url_params['r'])){
			$poi = explode('_',$this->url_params['r']);
			$crop_adjust_x = isset($poi[3])?$poi[3]:'5';
			$crop_adjust_y = isset($poi[4])?$poi[4]:'5';

			$this->crop_rectangle(
				$this->image,
				$this->url_params['w'],
				$this->url_params['h'],
				'0.'.$poi[0],
				'0.'.$poi[1],
				'0.'.$poi[2],
				'0.'.$crop_adjust_x,
				'0.'.$crop_adjust_y
			);
		}
		else
		{
			// Just Resize
			$this->image->resize($this->url_params['w'], $this->url_params['h']);
		}

		// Save
		if($this->isServeTypeChanged == TRUE){
			$this->image->save($this->cached_file.'.'.$this->serveExtension);
		}else{
			$this->image->save($this->cached_file);			
		}
	}


	/**
	 * Create the image HTTP headers
	 * 
	 * @param  string     path to the file to server (either default or cached version)
	 */
	private function _createHeaders($file_data)
	{
		// Image info
		$image_info	= getimagesize($file_data);
		
		// Create the required header vars
		$last_modified = gmdate('D, d M Y H:i:s', filemtime($file_data)).' GMT';
		$content_type = $image_info['mime'];
		$content_length = filesize($file_data);
		$expires = gmdate('D, d M Y H:i:s', (time() + $this->config['cache_expire'])).' GMT';
		$max_age = 'max-age='.$this->config['cache_expire'].', public';
		
		// Some required headers
		header("Last-Modified: $last_modified");
		header("Content-Type: $content_type");
		header("Content-Length: $content_length");

		// How long to hold in the browser cache
		header("Expires: $expires");

		/**
		 * Public in the Cache-Control lets proxies know that it is okay to
		 * cache this content. If this is being served over HTTPS, there may be
		 * sensitive content and therefore should probably not be cached by
		 * proxy servers.
		 */
		header("Cache-Control: $max_age");
		
		// Set the 304 Not Modified if required
		$this->_modifiedHeaders($last_modified);
		
		/**
		 * The "Connection: close" header allows us to serve the file and let
		 * the browser finish processing the script so we can do extra work
		 * without making the user wait. This header must come last or the file
		 * size will not properly work for images in the browser's cache
		 */
		header("Connection: close");
	}
	
	/**
	 * Rerurns 304 Not Modified HTTP headers if required and exits
	 * 
	 * @param  string  header formatted date
	 */
	private function _modifiedHeaders($last_modified)
	{  
		$modified_since = (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) ?
			stripslashes($_SERVER['HTTP_IF_MODIFIED_SINCE']) :
			FALSE;

		if ( ! $modified_since OR $modified_since != $last_modified)
			return;

		// Nothing has changed since their last request - serve a 304 and exit
		header('HTTP/1.1 304 Not Modified');
		header('Connection: close');
		exit();
	}
	
	/**
	 * Decide which filesource we are using and serve
	 */
	private function _serveFile()
	{
		// Set either the source or cache file as our datasource
		if ($this->serve_default)
		{
			$file_data = $this->source_file;
		}
		else
		{
			if($this->isServeTypeChanged == TRUE){
				$file_data = $this->cached_file.'.'.$this->serveExtension;
			}else{
				$file_data = $this->cached_file;
			}
		}
		
		// Output the file
		$this->_output_file($file_data);
	}
	
	/**
	 * Outputs the cached image file and exits
	 * 
	 * @param  string     path to the file to server (either default or cached version)
	 */
	private function _output_file($file_data)
	{
		// Create the headers
		$this->_createHeaders($file_data);
		
		// Get the file data
		$data = file_get_contents($file_data);

		// Send the image to the browser in bite-sized chunks
		$chunk_size	= 1024 * 8;
		$fp	= fopen('php://memory', 'r+b');
		
		// Process file data
		fwrite($fp, $data);
		rewind($fp);
		while ( ! feof($fp))
		{
			echo fread($fp, $chunk_size);
			flush();
		}
		fclose($fp);
		
		return $data;
		exit();
	}
}