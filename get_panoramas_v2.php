<?php
/*
*DISCLAIMER
* 
*THIS SOFTWARE IS PROVIDED BY THE AUTHOR 'AS IS' AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES  OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT,  INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*
*	@author: Olivier G. <olbibigo_AT_gmail_DOT_com>
*	@history:
*		1.0	creation
*		1.1	distance overlay added ('ov' parameter)
*		1.2	code refactoring, interpolation, 480 (SD) and 720 (HD) video format added ('hd' parameter)
*		1.3	iti nerary trace overlay added
*		1.4	waypoints added ('wa' parameter), video title added ('ti' parameter)
*		1.5	code refactoring,  algorithm based on pano metadata added ('fp' parameter)
*		1.6	bug in interpolation fixed
*		1.7	bug fixed
*	@prerequisite:	
*		FFMEPG software in the PATH (see http://www.ffmpeg.org/)
*		GoogleMapUtility.php and [$params->font_name] in working folder
*	@param:
*		or		string				origin address (mandatory)
*		de		string				destination address (mandatory)
*		wa	string				waypoints separated by '|' ('' by default)
*		ti		string				title of the video. If not set, '[or] -> [de]' string is used
*		ov		[true/false]		true: display name + distance + iti trace overlay,  false: hide (false by default)
*		hd		[true/false]		true: HD video format,  false: SD video format  (SD by default)
*		fp 	[true/false]		true: frames based on SV panorama metadata (FP mode), false: frames based on iti vertices   (false by default)
*		in		[true/false]		true: interpolate vertices,  false: use only route vertices  (false by default)
*
*		ex: get_panoramas_v2.php?or=Paris,FR&de=Orleans,FR&hd=true&ov=true
*		get_panoramas_v2.php?or=48.82510,2.38580&de=48.82536,2.38583&wa=48.90123,2.35286|48.84658,2.25458&hd=true&ov=true&fp=true&ti=Boulevard Peripherique, Paris
*/
	require_once('GoogleMapUtility.php');

	set_time_limit(3600);//in s
	ini_set('memory_limit', '256M');

	$startTime = microtime();

	//Video constants
	define('VIDEO_FORMAT_SD', 480);//Standard definition (SD) video format in pixels
	define('VIDEO_FORMAT_HD', 720);//High definition (HD) video format in pixels
	define('VIDEO_RATIO', 16/9);//HD video format in pixels	
	//SV constants
	define('SV_TILE_SIZE', 512);//Street View tile size		
	define('SV_DEG_PX_RATIO', 4 * SV_TILE_SIZE / 450);//At zoom level 2, 4 tiles (so 2048px) are used to give a 450° field of view
	define('SV_UTURN_OFFSET',  round(SV_DEG_PX_RATIO * 180));//in px
	//Misc constants
	define('TEMP_FOLDER', 'pics/'.time());	
				
	$header[0] = "Accept: text/xml,application/xml,application/xhtml+xml,";
	$header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
	$header[] = "Cache-Control: max-age=0";
	$header[] = "Connection: keep-alive";
	$header[] = "Keep-Alive: 300";
	$header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
	$header[] = "Accept-Language: en-us,en;q=0.5";
	$header[] = "Pragma: "; // browsers keep this blank. 
				
	$curl_options  = array( CURLOPT_USERAGENT => "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.11) Gecko/20071127 Firefox/2.0.0.11",
								CURLOPT_HTTPHEADER => $header, 
								CURLOPT_REFERER => 'http://www.test.com',  
								CURLOPT_ENCODING => 'gzip,deflate',						
								CURLOPT_HEADER => 0,
								CURLOPT_RETURNTRANSFER => true);
	//Store input parameters in a singleton class
	class Params{
		private static $instance = null;
		//Direct parameters
		public $origin = '';
		public $destination = '';	
		public $waypoints = '';
		public $has_overlay = false;
		public $has_interpolation = false;
		public $video_height = VIDEO_FORMAT_SD;
		public $next_from_pano = false;
		
		//Constants
		public $max_nb_vertices = 2000;//max number of vertices in the route to process. Related to route complexity	
		public $video_fps = 11;//Number of frames per second in output video
		public $font_name = 'digital-7.ttf';
		public $font_size = 20;//Font size in point in image overlay
		public $max_distance_vertices = 100;//Maximal distance in meters between vertices. Interpolation occures if current distance is bigger than that
		public $overlay_size = 200;//Maximal width and height in pixels of iti trace overlay
		public $sv_width_tiles  = 4;//Street View level 2 panorama is made of 4 tile columns			
		//sv_level2_height_tiles depends on chosen video format (SD=1 tile row, HD=2 tile row)	
		public $modulo_fp = 2;//in FP mode, there are SV panoramas about every 20m. This factor is used to extract panoramas with bigger distances by subsampling.
		
		//Built parameters
		public $video_name = '';//depends on origin and destination
		public $route_name = '';//depends on origin and destination
		public  $video_width =  0;//depends on chosen video format
		public $sv_height_tiles  = 0;//depends on chosen video format
		public $overlay_margin  = 0;
		public $ffmpeg  = '';		
		
		private function __construct()  {
			//Check input parameters
			if(!isset($_GET['or'])){
				die('Oups! Origin (or=...) parameter is not set.');
			}
			$this->origin = $_GET['or'];
	
			if(!isset($_GET['de'])){
				die('Oups! Destination (de=...) parameter is not set.');
			}
			$this->destination = $_GET['de'];

			if( isset($_GET['wa']) ){
				$this->waypoints = $_GET['wa'];
			}	
	
			if( isset($_GET['ti']) ){
				$this->video_name = str_replace(' ', '+', $_GET['ti']);
				$this->route_name  = $_GET['ti'];
			}else{	
				$this->video_name = str_replace(' ', '+', $this->origin.'_'.$this->destination);
				$this->route_name = $this->origin.' -> '.$this->destination;
			}

			$this->ffmpeg = 'ffmpeg -qscale 10 -r '.$this->video_fps.' -i '.TEMP_FOLDER.'/'.$this->video_name.'_%d.jpg '.$this->video_name.'.mp4 2>&1';				
			//Delete existing video file
			if(file_exists($this->video_name.'.mp4')){
				unlink($this->video_name.'.mp4');
			}			
	
			if(isset($_GET['ov']) & (0 == strcasecmp($_GET['ov'], 'true'))){
				$this->has_overlay = true;
				//Check if font file is available
				if(!file_exists($this->font_name)){
					die($this->font_name.' font file was not found!');
				}
			}

			if(isset($_GET['fp']) & (0 == strcasecmp($_GET['fp'], 'true'))){
				$this->next_from_pano = true;
			}

			if(isset($_GET['in']) & (0 == strcasecmp($_GET['in'], 'true'))){
				$this->has_interpolation = true;
			}

			if(isset($_GET['hd']) & (0 == strcasecmp($_GET['hd'], 'true'))){
				$this->video_height = VIDEO_FORMAT_HD;//in px
			}
			$this->video_width =  ceil($this->video_height * VIDEO_RATIO);//in px
			$this->sv_height_tiles = ((VIDEO_FORMAT_SD == $this->video_height) ? 1 : 2);//in nb of row
			
			$this->overlay_margin  = ceil($this->overlay_size/2);

			//Check if FFMPEG is available
			exec('ffmpeg', $ret, $err);
				if(0 == count($ret)){
					//die('FFMEPG executable was not found!');
				}
		}
		public static function getInstance() {
			if (!isset(self::$instance)) {
				$c = __CLASS__;
				self::$instance = new $c;
			}
			return self::$instance;
		}
		public function __clone() {}		
	}//Params
	
	///////////
	// MAIN //
	///////////
	//Get input parameters
	$params = Params::getInstance();

	//Create temporary folder to store SV thumbnails
	if(!is_dir(TEMP_FOLDER)){
		mkdir(TEMP_FOLDER);
	}

	$ch = curl_init();
	curl_setopt_array($ch, $curl_options);
	//Get directions from Google
	//echo 'http://maps.google.com/maps/api/directions/xml?sensor=false&origin='.urlencode($params->origin).'&destination='.urlencode($params->destination).'&waypoints='.$params->waypoints.'<br/>';exit;
	curl_setopt($ch, CURLOPT_URL, 'http://maps.google.com/maps/api/directions/xml?sensor=false&origin='.urlencode($params->origin).'&destination='.urlencode($params->destination).'&waypoints='.$params->waypoints); 
	$doc = new DOMDocument();
	
	global $images;
	$images = array();
	$xml = curl_exec($ch);
	file_put_contents(TEMP_FOLDER.'/waypoints.xml', $xml);
	
	if(FALSE == $doc->loadXML($xml)){
		die('Oups! Route cannot be determined between '.$params->origin.' and '.$params->destination);
	}
	curl_close($ch);
	//Get total distance
	$legs = $doc->getElementsByTagName('leg');
	$total_distance = 0;
	foreach ($legs as $leg){
		$distances = $leg->getElementsByTagName('distance');
		$total_distance +=  floatval($distances->item($distances->length-1)->nodeValue);//With the floatval, we don't need to get the 'value' node
	}
	//Get itinerary polyline
	$points_str = $doc->getElementsByTagName('points');
	$points = array();//array of panoPoint
	for ($i = 0; $i < $points_str->length-1; $i++) {
		$points = array_merge($points, fDecodeLine(trim($points_str->item($i)->nodeValue)));
	}
	//Remove doublons
	$max_index = count($points) - 1;
	$panoPoints[] = $points[0];//Add start point
	for ($i = 1; $i <= $max_index; ++$i){//loop through vertices of the polyline  
		if(0 != strcmp($points[$i-1],$points[$i])){	
			if($params->has_interpolation){//Interpolate
				fInterpolateVertices($points[$i-1],$points[$i],  $panoPoints);
			}
			$panoPoints[] = $points[$i];
		}
	}
	if(count($panoPoints) > $params->max_nb_vertices){
		die('Oups! Too many vertices for this route ('.count($panoPoints).'). You cannot exceed '. $params->max_nb_vertices.'.');
	}

	if($params->has_overlay){//Get iti trace overlay
		$overlay_image = fGetTraceFullOverlay($panoPoints, $z, $overlay_offset);	
		$green = imagecolorallocate($overlay_image, 0, 200, 0);
		imagesetthickness  ($overlay_image, 6);
	}
	if($params->next_from_pano){
		//Frame are based on pano metadata as long as 'text' is the same
		fCreateFramesFromPanoMetadata($panoPoints[0], $overlay_image, $overlay_offset,  $green, $z, $total_distance);	
	}else{
		//Frame are based on iti vertices (standard mode)
		fCreateFramesFromVertices($panoPoints, $overlay_image, $overlay_offset,  $green, $z, $total_distance);
	}
	if($params->has_overlay){
		imagedestroy($overlay_image);
		unset($overlay_image);
	}
	//Create video from images
	/////exec($params->ffmpeg, $ret, $err);
/*	sleep(ceil(count($panoPoints)*0.01));//give enough time to ffmpeg to do its business
	//Delete temporary thumbnails
	if ($handle = opendir(TEMP_FOLDER)) {
		while (false !== ($file = readdir($handle))) {
			if ($file != "." && $file != "..") {
				unlink(TEMP_FOLDER.'/'.$file);
			}
		}
		closedir($handle);
	}
*/
	//echo 'Over. Executed in '.number_format(round(fMicrotimeDiff($startTime)/1000)).'s. Memory peak: '.number_format(memory_get_peak_usage()).' bytes';
	
	$filejson = TEMP_FOLDER.'/pics.json';
	file_put_contents($filejson,  json_encode($images));
	
	header("Content-Type: application/json;charset=UTF-8");
	echo json_encode($images);
	
	/////////////////
	// FUNCTIONS //
	////////////////

	//Download a whole panorama from google servers
	//Return the panorama as a GD image
	function fDownloadPanorama($pano_id){
		global $curl_options;

		$params = Params::getInstance();
		//Initialize Curl multi
		for($y=0; $y<$params->sv_height_tiles; ++$y){
			$curl_handlers[]  = array();
			for($x=0; $x<$params->sv_width_tiles; ++$x){
				$ch = curl_init();
				curl_setopt_array($ch, $curl_options);
				$curl_handlers[$y][] = $ch;
			}
		}
		$mh = curl_multi_init();
		//Retrieve tiles from Google servers
		for($y=0; $y<$params->sv_height_tiles; ++$y){
			for($x=0; $x<$params->sv_width_tiles; ++$x){
				curl_setopt($curl_handlers[$y][$x], CURLOPT_URL, 'http://cbk'.$x.'.google.com/cbk?output=tile&zoom=2&panoid='.$pano_id.'&y='.$y.'&x='.$x);
				curl_multi_add_handle($mh,$curl_handlers[$y][$x]);	
			}
		}
		$running=null;
		//execute the handlers
		do {
			usleep(10000);
			curl_multi_exec($mh,$running);
		} while($running > 0);
		$merged_image = imagecreatetruecolor($params->sv_width_tiles * SV_TILE_SIZE, $params->sv_height_tiles  * SV_TILE_SIZE);
		for($y=0; $y<$params->sv_height_tiles; ++$y){
			for($x=0; $x<$params->sv_width_tiles; ++$x){
				//Merge all tiles
				$tile = imagecreatefromstring(curl_multi_getcontent($curl_handlers[$y][$x]));
				imagecopy($merged_image, $tile, SV_TILE_SIZE*$x, SV_TILE_SIZE*$y, 0, 0, SV_TILE_SIZE, SV_TILE_SIZE);
				imagedestroy($tile);
				unset($tile);
			}
		}
		//Curl clean up
		for($y=0; $y<$params->sv_height_tiles; ++$y){
			for($x=0; $x<$params->sv_width_tiles; ++$x){
				curl_multi_remove_handle($mh, $curl_handlers[$y][$x]);
				curl_close($curl_handlers[$y][$x]);
			}
		}					
		curl_multi_close($mh);
		//Return the panorama as a GD image
		return $merged_image;
	}//fDownloadPanorama

	//creates a set of frames based on iti vertices (panoPoints). This is the standard mode
	function fCreateFramesFromVertices($panoPoints, $overlay_image, $overlay_offset,  $green, $z, $total_distance){
		global $curl_options;
		
		$params = Params::getInstance();

		$distance = 0;
		$index_panos = 0;
		$max_index = count($panoPoints)-1;	
	
		for ($i = 0; $i <= $max_index; ++$i){//loop through vertices of the polyline
			if($i>0){//Get current distance
				$distance += fGetDistance($panoPoints[$i-1], $panoPoints[$i]);
			}
			$ch = curl_init();
			curl_setopt_array($ch, $curl_options);
			curl_setopt($ch, CURLOPT_URL, 'http://cbk0.google.com/cbk?output=xml&ll='.$panoPoints[$i]->y.','.$panoPoints[$i]->x);
			$xml = @simplexml_load_string(curl_exec($ch));
			curl_close($ch);
			if( (FALSE !== $xml) && isset($xml->data_properties) ){
				$pano_id = $xml->data_properties['pano_id'];
			}else{
				continue;
			}			
			//Download a whole panorama from google servers
			$merged_image = fDownloadPanorama($pano_id);	
			//Get bearing
			if($i == $max_index){//last point
				$yaw = fGetBearing($panoPoints[$i-1], $panoPoints[$i]);		
			}else{
				$yaw = fGetBearing($panoPoints[$i], $panoPoints[$i+1]);
			}
			$merged_offset = fGetFOVOffset($yaw, $xml);
			fCreateFrame($panoPoints[$i],  (($i==0)?null:$panoPoints[$i-1]), $merged_image,  $merged_offset,  $overlay_image, $overlay_offset,  $green, $z, $distance, $total_distance, $index_panos++);
		}
	}//fCreateFramesFromVertices()
	
	//creates a set of frames based on SV metadata as long as 'text' is the same as in the first pano.
	//this mode target iti using a unique road (ex: Boulevard Peripherique of Paris)
	function fCreateFramesFromPanoMetadata($origin_point, $overlay_image, $overlay_offset,  $green, $z, $total_distance){
		global $curl_options;
		
		$params = Params::getInstance();
	
		//Get data from first pano
		$ch = curl_init();
		curl_setopt_array($ch, $curl_options);
		curl_setopt($ch, CURLOPT_URL, 'http://cbk0.google.com/cbk?output=xml&ll='.$origin_point->y.','.$origin_point->x);
		$xml = @simplexml_load_string(curl_exec($ch));
		if( (FALSE !== $xml) && isset($xml->data_properties) ){
			$ref_text = $xml->data_properties->text;
			$pano_id = $xml->data_properties['pano_id'];
			$origin_pano_id = $pano_id;	
			$yaw = floatval($xml->projection_properties['pano_yaw_deg']);
		}else{
			die('Oups! Cannot extract reference text from first panorama: '.$pano_id);
		}
		$previous_panoPoint = null;
		$panoPoint = $origin_point;
		if(!isset($_GET['re'])){
			$distance = 0;
			$index_panos = 0;//analysed panos
			$index_frames = 0;//converted panos (subsamples of analysed panos)
		}else{
			//Use backup data
			list($distance, $index_panos, $index_frames,$x, $y) = explode(';', $_GET['re']);
			$overlay_offset = new Point($x, $y);
			//Load backup overlay
			imagedestroy($overlay_image);
			$overlay_image = imagecreatefrompng($params->video_name.'_'.$index_panos.'_ov.png');
		}
		
		$max_distance = 1.1 * $total_distance;
		do{//loop forever until..
			//Download a whole panorama from google servers
			//if(!($index_panos % $params->modulo_fp)){//Convert panos to frames
				$merged_image = fDownloadPanorama($pano_id);
				fCreateFrame($panoPoint, $previous_panoPoint, $merged_image,  fGetFOVOffset(),  $overlay_image, $overlay_offset,  $green, $z, $distance, $total_distance, $index_frames++);
				//echo $index_panos.'OK   '.$pano_id.': '.$distance.'/'.$total_distance.'<br/>';
			//}else{
				//echo $index_panos.'KO   '.$pano_id.': '.$distance.'/'.$total_distance.'<br/>';			
			//}
			//get POV and next panorama from metadata
			if(isset($xml->annotation_properties) ){
				$yaw = floatval($xml->projection_properties['pano_yaw_deg']);
				$panos_found = array();
				foreach($xml->annotation_properties->link as $link){//loop through all links and extract suitable ones.
					if( (abs(floatval($link['yaw_deg']) - $yaw) < 90) && (0 == strcasecmp($link->link_text, $ref_text)) ){
						$panos_found[(string)$link['pano_id']] = floatval($link['yaw_deg']);
					}
				}
				if(empty($panos_found)){
						//try to jump over a small  hole in SV coverage
						$new_lat =  $panoPoint->y + 2 * ($panoPoint->y - $previous_panoPoint->y);
						$new_lng =  $panoPoint->x + 2 * ($panoPoint->x - $previous_panoPoint->x);
						//echo 'NF: http://cbk'.rand(0,3).'.google.com/cbk?output=xml&ll='.$new_lat.','.$new_lng.'<br/>';
						curl_setopt($ch, CURLOPT_URL, 'http://cbk'.rand(0,3).'.google.com/cbk?output=xml&ll='.$new_lat.','.$new_lng);
						$xml = @simplexml_load_string(curl_exec($ch));
						if( (FALSE !== $xml) && isset($xml->data_properties) && (0 == strcasecmp($xml->data_properties->text, $ref_text)) ){
							$pano_id = $xml->data_properties['pano_id'];
						}else{
							//Store overlay for future use
							imagepng($overlay_image, $params->video_name.'_'.$index_panos.'_ov.png');
							die('Oups! Cannot guess next panorama: '.$pano_id.' &re='.$distance.';'.$index_panos.';'.$index_frames.';'.$overlay_offset->x.';'.$overlay_offset->y);				
						}						
				}else{
					//Take link with the smallest deviation
					asort($panos_found);
					reset($panos_found);
					$pano_id = key($panos_found);
				}
				//Loop as long as road label keeps the same, origin pano is not met again and total distance is below what it should be (with a little margin).
				if( ($pano_id != $origin_pano_id) && ($distance < $max_distance) /*&& ($index_panos < 20)*/){
					//Get data from next pano
					curl_setopt($ch, CURLOPT_URL, 'http://cbk'.rand(0,3).'.google.com/cbk?output=xml&panoid='.$pano_id);
					$xml = @simplexml_load_string(curl_exec($ch));
					if( (FALSE !== $xml) && isset($xml->data_properties) ){
						$previous_panoPoint = $panoPoint;
						$panoPoint = new Point(floatval($xml->data_properties['lng']), floatval($xml->data_properties['lat']));
						$distance += fGetDistance($previous_panoPoint, $panoPoint);
						$yaw = floatval($xml->projection_properties['pano_yaw_deg']);
					}else{
						die('Oups! Cannot extract data from next panorama: '.$pano_id);
					}
				}else{
					break;
				}
			}else{
				break;
			}
			++$index_panos	;		
		}while(1);
		curl_close($ch);
	}//fCreateFramesFromPanoMetadata

	//Create a frame based on an exract from the whole panorama
	function fCreateFrame($panoPoint, $previous_panoPoint, $merged_image,  $merged_offset,  $overlay_image, $overlay_offset, $iti_color, $z, $distance, $total_distance, $index_panos){
		$params = Params::getInstance();
			
		//Extract part of the marged image corresponding to field of view
		$fov_image  = imagecreatetruecolor($params->video_width, $params->video_height); 
		$merged_width = $params->sv_width_tiles * SV_TILE_SIZE;
		$merged_height = $params->sv_height_tiles  * SV_TILE_SIZE;
		//Depending on video format, we extract part of the merged image.
		switch($params->video_height){
			case VIDEO_FORMAT_SD:
				//Merged image is 4*SV_TILE_SIZE, SV_TILE_SIZE. We keep the whole bottom part
				imagecopy($fov_image, $merged_image, 0, 0, $merged_offset, $merged_height - $params->video_height, $params->video_width, $params->video_height);				
				break;
			case VIDEO_FORMAT_HD:
				//Merged image is 4*SV_TILE_SIZE, 2*SV_TILE_SIZE.
				imagecopy($fov_image, $merged_image, 0, 0, $merged_offset, 0, $params->video_width, $params->video_height);			
				break;
			default:
				die('Oups! Unknown format.');
		}
		
		if($params->has_overlay){//Add overlay (route name, current distance, iti trace)
			$xy2 = GoogleMapUtility::getPixelCoords($panoPoint->y, $panoPoint->x, $z);
			if( !is_null($previous_panoPoint) ){//Add current iti trace (in green)
				$xy1 = GoogleMapUtility::getPixelCoords($previous_panoPoint->y, $previous_panoPoint->x, $z);
				imageline  ($overlay_image, $xy1->x - $overlay_offset->x, $xy1->y - $overlay_offset->y, $xy2->x - $overlay_offset->x, $xy2->y - $overlay_offset->y, $iti_color);
			}	
			//Merge part of the overlay centred on current position
			imagecopymerge($fov_image, $overlay_image, $params->video_width - $params->overlay_size - 20, $params->video_height - $params->overlay_size - 20, $xy2->x -  $overlay_offset->x - $params->overlay_margin, $xy2->y -  $overlay_offset->y -$params->overlay_margin, $params->overlay_size, $params->overlay_size, 100);

			$gray = imagecolorallocate($fov_image, 128, 128, 128);
			$red = imagecolorallocate($fov_image, 255, 0, 0);
			imagettftext($fov_image, $params->font_size, 0, 11, 31, $gray, $params->font_name, $params->route_name);
			imagettftext($fov_image, $params->font_size, 0, 10, 30, $red, $params->font_name, $params->route_name);
			if($total_distance < 10000){//10km
				$total_distance = number_format($total_distance / 1000, 1).'Km';//X.Y Km
			}else{
				$total_distance = round($total_distance / 1000).'Km';//round KM	
			}
			$text = number_format($distance / 1000, 1).'/'.$total_distance;			
			imagettftext($fov_image, $params->font_size, 0, 11, 61, $gray, $params->font_name, $text);
			imagettftext($fov_image, $params->font_size, 0, 10, 60, $red, $params->font_name, $text);
		}
		imagejpeg($fov_image, TEMP_FOLDER.'/'.$params->video_name.'_'.$index_panos.'.jpg', 85);
		
		global $images;
		$images[] = TEMP_FOLDER.'/'.$params->video_name.'_'.$index_panos.'.jpg';
		
		// echo "<pre>";
		//echo TEMP_FOLDER.'/'.$params->video_name.'_'.$index_panos.'.jpg\n-\n';
		// print_r($images);
		// flush();
		//Clean up
		imagedestroy($merged_image);
		unset($merged_image);		
		imagedestroy($fov_image);
		unset($fov_image);	
	}//fCreateFrame
	
	//Decode an encoded polyline
	//Return an array of Point(lat, lng)
	function fDecodeLine($encoded){
		$len = strlen($encoded);
		$index = 0;
		$lat = 0;
		$lng = 0;

		while ($index < $len) {
			$shift = 0;
			$result = 0;
			do {
				$b = ord(substr($encoded,$index++,1)) - 63;
				$result |= ($b & 0x1f) << $shift;
				$shift += 5;
			} while ($b >= 0x20);
			$dlat = (($result & 1) ? ~($result >> 1) : ($result >> 1));
			$lat += $dlat;

			$shift = 0;
			$result = 0;
			do {
				$b = ord(substr($encoded,$index++,1)) - 63;
				$result |= ($b & 0x1f) << $shift;
				$shift += 5;
			} while ($b >= 0x20);
			$dlng = (($result & 1) ? ~($result >> 1) : ($result >> 1));
			$lng += $dlng;
			$points[] = new Point($lng * 1e-5, $lat * 1e-5);//lng -> x and lat -> y
		}
		
		return $points;
	}//fDecodeLine
	
	//Get angle between 2 vertices (Point type)
	function fGetBearing($origin, $destination){
		if ( 0 == strcmp($origin,$destination)) {
			return 0;
		}
		$lat1 = deg2rad($origin->y);
		$lat2 = deg2rad($destination->y);
		$dLon = deg2rad($destination->x - $origin->x);
		$y = sin($dLon) * cos($lat2);
		$x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLon);
		return (rad2deg(atan2($y, $x)) +360 ) % 360;
	}//fGetBearing
	
	//Get offset in pixel corresponding to the left border of the FOV in the merged image 
	function fGetFOVOffset($yaw=null, $xml=null){
		$params = Params::getInstance();
		
		$pivot = 840;
		
		if( !is_null($yaw) ){
			if( isset($xml->projection_properties) ){
					$pano_yaw_deg = floatval($xml->projection_properties['pano_yaw_deg']);
					
					//The dirty part: we assume a few things
					//only 2 roads: one centered on $pano_yaw_deg and another one on ($pano_yaw_deg+180)%360
					//$pano_yaw_deg = offset of 840px (rule of thumbs)
					
					//There are for sure a more accurate way of finding the offset but until now, I don't know yet
					//how to use correctly pano_yaw_deg, tilt_yaw_deg and link yaw_deg to compute a more
					//correct FOV offset :(
					
					//Compute delta angle between target yaw and pano yaw
					$angle = abs($yaw-$pano_yaw_deg) % 200;
					if($angle< 90){//we use the left road window
						return $pivot - ceil($params->video_width/2);
					}else{//we use the right road window
						return $pivot + SV_UTURN_OFFSET - ceil($params->video_width/2);				
					}
			}else{
				return  2 * SV_TILE_SIZE - ceil($params->video_width/2);//middle
			}
		}else{//Return always left part of the panorama
			return $pivot - ceil($params->video_width/2);
		}
	}//fGetOffset
	
	//Get distance between 2 locations (Point type)
	function fGetDistance($point1, $point2){
		//based on	Haversine formula
		$dLat = deg2rad($point2->y - $point1->y);
		$dLon = deg2rad($point2->x - $point1->x); 
		$a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($point1->y)) * cos(deg2rad($point2->y)) * sin($dLon/2) * sin($dLon/2); 
		return floor(6371000 * 2 * atan2(sqrt($a), sqrt(1-$a)));		
	}//fGetDistance
	
	//Extract interpolated points
	function fInterpolateVertices($point1, $point2, &$panoPoints){
		$params = Params::getInstance();	
		
		if(fGetDistance($point1, $point2) > $params->max_distance_vertices){					
			$new_point = new Point($point1->x + ($point2->x - $point1->x)/2 ,   $point1->y + ($point2->y - $point1->y)/2);			
			//recursivity on left section
			fInterpolateVertices($point1,  $new_point,  &$panoPoints);			
			//Create a new vertice in the middle
			$panoPoints[] = $new_point;
			//recursivity on right section
			fInterpolateVertices($new_point,  $point2,  &$panoPoints);
		}
	}//fIntepolateVertices

	//Performance timer
    function fMicrotimeDiff( $aStart, $aEnd=NULL ) {
        if( !$aEnd ) {
            $aEnd= microtime();
        }
        list($start_usec, $start_sec) = explode(" ", $aStart);
        list($end_usec, $end_sec) = explode(" ", $aEnd);
        $diff_sec = intval($end_sec) - intval($start_sec);
        $diff_usec = floatval($end_usec) - floatval($start_usec);
        return intval(1000 * (floatval( $diff_sec ) + $diff_usec));//in ms
    }//fMicrotimeDiff
	
	//Get itinerary trace overlay
	//Return the overlay as a GD image
	function fGetTraceFullOverlay($panoPoints, &$z, &$overlay_offset){
		global $curl_options;
		
		$params = Params::getInstance();
		//Find geo-bounds
		$min_lat = 90.0;
		$max_lat = -90.0;
		$min_lng =  180.0;
		$max_lng = -180.0;
		for ($i = 0; $i < count($panoPoints); ++$i){
			$min_lat = min($min_lat, $panoPoints[$i]->y);
			$max_lat = max($max_lat, $panoPoints[$i]->y);
			$min_lng = min($min_lng, $panoPoints[$i]->x);
			$max_lng = max($max_lng, $panoPoints[$i]->x);
		}
		$z = 17;
		do{//Find zoom level that fits target height
			--$z;
			$xy_min = GoogleMapUtility::getPixelCoords($max_lat, $min_lng, $z);
			$xy_max = GoogleMapUtility::getPixelCoords($min_lat, $max_lng, $z);
			if( (($xy_max->y - $xy_min->y) < $params->overlay_size) && (($xy_max->x - $xy_min->x) < $params->overlay_size)){
				//Zoom in 2 levels
				if(15 >= $z){
					$z +=2;
				}
				//Get tile bounds (with margin)
				$xy_min = GoogleMapUtility::getPixelCoords($max_lat, $min_lng, $z);
				$xy_max = GoogleMapUtility::getPixelCoords($min_lat, $max_lng, $z);		
				$Tile_min  = new Point(floor(($xy_min->x - $params->overlay_margin) / GoogleMapUtility::TILE_SIZE), floor(($xy_min->y - $params->overlay_margin) / GoogleMapUtility::TILE_SIZE));
				$Tile_max  = new Point(floor(($xy_max->x + $params->overlay_margin) / GoogleMapUtility::TILE_SIZE), floor(($xy_max->y + $params->overlay_margin) / GoogleMapUtility::TILE_SIZE));					
				$overlay_offset = new Point($xy_min->x - $params->overlay_margin,  $xy_min->y - $params->overlay_margin);
				break;
			}
		}while($z>0);
		//Initialize complete overlay image
		$overlay_image = imagecreatetruecolor( 2 * $params->overlay_margin + $xy_max ->x - $xy_min->x,  2 * $params->overlay_margin + $xy_max->y-$xy_min->y);
		$white = imagecolorallocate($overlay_image, 255, 255, 255);
		imagefilledrectangle($overlay_image, 0, 0, imagesx($overlay_image), imagesy($overlay_image), $white);
		imagecolortransparent  ($overlay_image, $white);
		
		//get hybrid tiles from google
		$ch = curl_init();
		curl_setopt_array($ch, $curl_options);		
		for($y=$Tile_min->y; $y<=$Tile_max->y; ++$y){
			for($x=$Tile_min->x; $x<=$Tile_max->x; ++$x){
				curl_setopt($ch, CURLOPT_URL, 'http://mt'.rand(0,3).'.google.com/vt/lyrs=h@132&x='.$x.'&y='.$y.'&z='.$z); 
				$tile_image = imagecreatefromstring (curl_exec($ch));				
				imagecopymerge($overlay_image, $tile_image, $x * GoogleMapUtility::TILE_SIZE  - $overlay_offset->x, $y * GoogleMapUtility::TILE_SIZE  - $overlay_offset->y, 0, 0, GoogleMapUtility::TILE_SIZE,  GoogleMapUtility::TILE_SIZE, 99);
				imagedestroy($tile_image);
				unset($tile_imag);		
			}
		}
		curl_close($ch);
		
		//Add full iti trace (in red)
		$red = imagecolorallocate($overlay_image, 255, 0, 0);
		$max_index = count($panoPoints)-1;		
		$xy1 = GoogleMapUtility::getPixelCoords($panoPoints[0]->y, $panoPoints[0]->x, $z);		
		imagesetthickness  ($overlay_image, 2);
		for ($i = 1; $i <= $max_index; ++$i){//loop through vertices of the polyline
			$xy2 = GoogleMapUtility::getPixelCoords($panoPoints[$i]->y, $panoPoints[$i]->x, $z);		
			imageline  ($overlay_image, $xy1->x - $overlay_offset->x, $xy1->y - $overlay_offset->y, $xy2->x - $overlay_offset->x, $xy2->y - $overlay_offset->y, $red);
			$xy1= $xy2;
		}
		//Return the overlay as a GD image
		return $overlay_image;
	}//fGetTraceFullOverlay
?>