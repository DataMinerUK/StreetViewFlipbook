<!doctype html>
<html>
	<head>
		<meta charset="utf-8"/>
		<title>magic</title>
		<!--[if lt IE 9]>
			<script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
		<![endif]-->
		<link rel="stylesheet" media="all" href="style.css"/>
		<meta name="viewport" content="width=device-width, initial-scale=1"/>
		<style>
			body > img {
				width: 100%;
				height:100%;
				position: fixed;
				z-index: 5;
			}
			
			body > div {
				position: absolute;
				top: 0;
				left: 0;
				z-index: 10;
			}
			
			form {
				display: none;
			}
			
			fieldset {
				width: 33%;
				margin: auto;
				margin-top: 50px;
				padding: 20px;
				border: 1px solid white;
			}
			
			legend {
				padding: 0 5px;
			}
			
		</style>
		
		<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
		<script>
		var prefix = "<?php 
		if (isset($_GET['prefix'])) {
			echo $_GET['prefix'];
		} else {
			echo "DEFAULT";
		}
		?>";
		
			$(function(){
				var pics;
				var $image = $('<img />').appendTo('body');
				
				$.get( (prefix != 'DEFAULT' ? prefix : "pics/1327859008/") + 'pics.json', function(data) {
					pics = data;
					$image.attr("src", pics[0]);
				});
				
				var $c = $('#content');//$('<div></div>').appendTo('body');
				for(var i = 0; i < 500; i++) {
					$c.append('<p>hello</p>');
				}
				
				$(window).scroll(function(){
					var p = Math.floor(pics.length * $(window).scrollTop() / ($(document).height() - $(window).height()));
					
					$image.attr("src", pics[p]);
					
				});
				
				if (prefix == 'DEFAULT') {
					$('form').show().submit(function(){
						$('fieldset').append('<img src="loader.gif">').find('input[type=submit]').remove();
						
						$.ajax({
							url: $('form').attr("action"),
							type: "GET",
							dataType: "json",
							data: $('form').serialize(),
							success: function(data) {
								$('form').hide();
								pics = data;
								$(window).scrollTop(0).trigger('scroll');
							}
						});
						
						return false;
					});
				}
			});
		</script>
	</head>
	<body lang="en">
		<div id="content">
		<form action="get_panoramas_v2.php">
			<fieldset>
				<legend>Please play</legend>
			<input name="or" placeholder="from">
			<input name="de" placeholder="to">
			
			<input type="hidden" name="hd" value="true">
			<input type="hidden" name="ov" value="true">
			
			<input type="submit" value="Go">
			</fieldset>
		</form>
	</div>
		
	</body>
</html>