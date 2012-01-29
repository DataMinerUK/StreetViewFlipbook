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
*	@param:
*		or		string	origin address (mandatory)
*		de		string	destination address (mandatory)
*		wa	string	waypoints ('' by default)
*		mode	[0,1]		0: display only route, 1: check panoramas (0 by default)
*
*	ex: check_panoramas.php?or=Paris,FR&de=Vincennes,FR&mode=1
*	     check_panoramas.php?or=48.90078,2.35807&de=48.90078,2.35807&wa=48.82838,2.39697|48.83952,2.25417
*/
	require_once('GoogleMapUtility.php');

	set_time_limit(3600);//in s
	ini_set('memory_limit', '256M');
	
	define('MAX_NB_VERTICES', 500);//related to route complexity	
	define('IMAGE_MAX_LENGTH', 1000);//in px
	define('MARGIN_RATIO', 0.05);//in %
	
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
	
	$ch = curl_init();
	curl_setopt_array($ch, array( CURLOPT_HEADER => false,
								CURLOPT_RETURNTRANSFER => true,
								CURLOPT_BINARYTRANSFER => true));

	curl_setopt($ch, CURLOPT_URL, 'http://maps.google.com/maps/api/directions/xml?sensor=false&origin='.urlencode($origin).'&destination='.urlencode($destination).'&waypoints='.$waypoints);
	$doc = new DOMDocument();
	if(FALSE == $doc->loadXML(curl_exec($ch))){
		die('Oups! Route cannot be determined betwwen '.$origin.' and '.$destination);
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
	//Add a little margin
	$margin_lat = ($max_lat - $min_lat) * MARGIN_RATIO;
	$margin_lng = ($max_lng - $min_lng) * MARGIN_RATIO;
	$min_lat -= $margin_lat;
	$max_lat += $margin_lat;
	$min_lng -= $margin_lng;
	$max_lng += $margin_lng;
	
	$z = 17;
	do{//Find zoom level that fits target height
		--$z;
		$xy_min = GoogleMapUtility::getPixelCoords($max_lat, $min_lng, $z);
		$xy_max = GoogleMapUtility::getPixelCoords($min_lat, $max_lng, $z);
		if( (($xy_max->y - $xy_min->y) < IMAGE_MAX_LENGTH) && (($xy_max->x - $xy_min->x) < IMAGE_MAX_LENGTH)){			
			$wh = new Point($xy_max->x - $xy_min->x, $xy_max->y - $xy_min->y);
			$xy_offset = new Point($xy_min->x, $xy_min->y);
			break;
		}
	}while($z>0);

	$im = imagecreatetruecolor($wh->x, $wh->y);
	$black = imagecolorallocate($im, 0, 0, 0);
	$red = imagecolorallocate($im, 255, 0, 0);
	$green = imagecolorallocate($im, 0, 255, 0);
	$white = imagecolorallocate($im, 255, 255, 255);
	imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
	$pano_ids = array();//Array used to check doublons
	for ($i = 0; $i < count($panoPoints); ++$i){//loop through vertices of the polyline
		$xy = GoogleMapUtility::getPixelCoords($panoPoints[$i]->y, $panoPoints[$i]->x, $z);
		imagesetpixel($im, $xy->x-$xy_offset->x, $xy->y-$xy_offset->y, $red);
		if($mode > 0){//Check if Street View panoramas exist for current location
			//Could curl_ multi_ exec()  be better?
			curl_setopt($ch, CURLOPT_URL, 'http://cbk0.google.com/cbk?output=xml&ll='.$panoPoints[$i]->y.','.$panoPoints[$i]->x);
			$doc = simplexml_load_string(curl_exec($ch));
			if( (FALSE !== $doc) && isset($doc->data_properties) ){
				imagesetpixel($im, $xy->x-$xy_offset->x, $xy->y-$xy_offset->y, $green);
				if(!in_array($doc->data_properties['pano_id'], $pano_ids)){
					$pano_ids[] = $doc->data_properties['pano_id'];							
				}			
			}
		}
	}
	//Set origin milestone
	$xy = GoogleMapUtility::getPixelCoords($panoPoints[0]->y, $panoPoints[0]->x, $z);
	$start_x = $xy->x - $xy_offset->x; 
	$start_y = $xy->y - $xy_offset->y;
	imageellipse($im, $start_x, $start_y, 8, 8, $white);
	if($start_x < ($wh->x/2)){
		imagestring($im, 3, $start_x+10, $start_y, ucfirst(strtolower($origin)), $white);
	}else{
		imagestring($im, 3, $start_x-10-strlen($origin)*7, $start_y, ucfirst(strtolower($origin)), $white);	
	}
	//Set destination milestone
	$xy = GoogleMapUtility::getPixelCoords($panoPoints[count($panoPoints)-1]->y, $panoPoints[count($panoPoints)-1]->x, $z);	
	$end_x = $xy->x - $xy_offset->x;  
	$end_y = $xy->y - $xy_offset->y;	
	imageellipse($im, $end_x, $end_y, 8, 8, $white);	
	if($end_x < ($wh->x/2)){
		imagestring($im, 3, $end_x+10, $end_y, ucfirst(strtolower($destination)), $white);
	}else{
		imagestring($im, 3, $end_x-10-strlen($destination)*7, $end_y, ucfirst(strtolower($destination)), $white);	
	}
	$out = count($panoPoints).' vertices';
	if($mode > 0){
		$out .= ' and '.count($pano_ids).' unique panoramas';
	}
	imagestring($im, 3, 5, 5, $out, $white);

	//return image
	header('content-type:image/png;');
	imagepng($im);
	imagedestroy($im);
	unset($im);
	curl_close($ch);

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
?>
