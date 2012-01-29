<?php
/*
*DISCLAIMER
* 
*THIS SOFTWARE IS PROVIDED BY THE AUTHOR 'AS IS' AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES *OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, *INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF *USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT *(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*
*	@author: Olivier G. <olbibigo_AT_gmail_DOT_com>
*	@history:
*		1.0	creation
*		1.1	parameter names changed, waypoints added
*	@prerequisite:	FFMEPG software in the PATH (see http://www.ffmpeg.org/)
*	@param:
*		or		string	origin address (mandatory)
*		de		string	destination address (mandatory)
*		wa	waypoints ('' by default)
*		mode	[0,1]		0: use all thumbnail, 1: use only half central thumbnail (0 by default)
*
*		ex: get_panoramas.php?or=Paris,FR&de=Vincennes,FR&mode=1
*		     get_panoramas.php?or=48.90078,2.35807&de=48.90078,2.35807&wa=48.82838,2.39697|48.83952,2.25417
*/
	require_once('GoogleMapUtility.php');

	set_time_limit(3600);//in s
	ini_set('memory_limit', '256M');

	define('MAX_NB_VERTICES', 500);//related to route complexity	
	define('TEMP_FOLDER', 'pics/'.time());		
	define('VIDEO_FPS', 11);//in frame per second
	
	//Check input parameters
	if(!isset($_GET['or'])){
		die('Oups! Origin (or=...) parameter is not set.');
	}
	$origin=$_GET['or'];
	if(!isset($_GET['de'])){
		die('Oups! Destination (de=...) parameter is not set.');
	}
	$destination=$_GET['de'];
	$waypoints = '';
	if( isset($_GET['wa']) ){
		$waypoints = $_GET['wa'];
	}
	$mode = 0;
	if( isset($_GET['mode']) ){
		$mode = intval($_GET['mode']);
	}

	$video_name= str_replace(' ', '+', $origin.'_'.$destination);
	
	$ch = curl_init();
	curl_setopt_array($ch, array( CURLOPT_HEADER => false,
								CURLOPT_RETURNTRANSFER => true,
								CURLOPT_BINARYTRANSFER => true) );
	curl_setopt($ch, CURLOPT_URL, 'http://maps.google.com/maps/api/directions/xml?sensor=false&origin='.urlencode($origin).'&destination='.urlencode($destination).'&waypoints='.$waypoints); 
	$doc = new DOMDocument();
	if(FALSE == $doc->loadXML(curl_exec($ch))){
		die('Oups! Route cannot be determined between '.$origin.' and '.$destination);
	}

	$points_str = $doc->getElementsByTagName('points');
	$points = array();//array of panoPoint
	for ($i = 0; $i < $points_str->length-1; $i++) {
		$points = array_merge($points, fDecodeLine(trim($points_str->item($i)->nodeValue)));
	}
	//Remove doublons
	$max_index = count($points) - 2;
	for ($i = 0; $i < $max_index; ++$i){//loop through vertices of the polyline  
		if(0 != strcmp($points[$i],$points[$i+1])){
			$panoPoints[] = $points[$i];
		}
	}
	$panoPoints[] = $points[count($points)-1];	

	if(count($panoPoints)>MAX_NB_VERTICES){
		die('Oups! Too many vertices for this route ('.count($panoPoints).'). You cannot exceed '.MAX_NB_VERTICES.'.');
	}
	//Create temporary folder to store SV thumbnails
	if(!is_dir(TEMP_FOLDER)){
		mkdir(TEMP_FOLDER);
	}

	$max_index = count($panoPoints)-1;
	$index_panos = 0;
	$images = array();
	for ($i = 0; $i <= $max_index; ++$i){//loop through vertices of the polyline
		$xy = GoogleMapUtility::getPixelCoords($panoPoints[$i]->y, $panoPoints[$i]->x, $z);
		if($i == $max_index){//last point
			$yaw = fGetBearing($panoPoints[$i-1], $panoPoints[$i]);		
		}else{
			$yaw = fGetBearing($panoPoints[$i], $panoPoints[$i+1]);
		}
		$url = 'http://cbk0.google.com/cbk?output=thumbnail&ll='.$panoPoints[$i]->y.','.$panoPoints[$i]->x.'&yaw='.$yaw;
		if(1 == $mode){
			$url .= '&w=312&h=208';
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		$filename = TEMP_FOLDER.'/'.$video_name.'_'.($index_panos++).'.jpg';
		//Could curl_ multi_ exec()  be better?
		if( FALSE !== ($raw_image = curl_exec($ch)) ){
			file_put_contents($filename, $raw_image);
			$images[] = $filename;
		}else{
			//Probably, panorama does not exist for current location. Do nothing.
		}
	}
	curl_close($ch);
	
	$filejson = TEMP_FOLDER.'/pics.json';
	file_put_contents($filejson,  json_encode($images));
	
	$makeMovieFFmpeg = 'ffmpeg -r '.VIDEO_FPS.' -f image2 -i '.TEMP_FOLDER.'/'.$video_name.'_%d.jpg '.$video_name.'.mp4 2>&1';
	//exec($makeMovieFFmpeg,$ret,$err);
	// echo $makeMovieFFmpeg;
	sleep(ceil(count($panoPoints)*0.01));//give enough time to ffmpeg to do its business
	//Delete temporary thumbnails
	/*if ($handle = opendir(TEMP_FOLDER)) {
			while (false !== ($file = readdir($handle))) {
				if ($file != "." && $file != "..") {
					unlink(TEMP_FOLDER.'/'.$file);
				}
			}
			closedir($handle);
		}*/
	//echo 'Over :)';
	//header('Location: index.php?prefix='.TEMP_FOLDER.'/');
	
	header("Content-Type: application/json;charset=UTF-8");
	echo json_encode($images);

	// This function is from Google's polyline utility.
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
?>
