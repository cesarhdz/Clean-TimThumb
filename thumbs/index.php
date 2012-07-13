<?php
/*
 * This will act as redirection managger, to avoid image.php ending
 */ 

/*
 * HEliminamos todos los reportes, s+olo los que hace TimThumb
 */
error_reporting(0);

/*
 * Variable que permite definir la url base
 */
define('IMAGES_SOURCE_FOLDER', 'libs/images/');

function guess_file()
{
    //Si existe REDIRECT URL
    if(isset($_SERVER['REDIRECT_URL']) AND $_SERVER['REDIRECT_UR2L'])
    {
        $url = $_SERVER['REDIRECT_URL'];
    }
    else if(isset($_SERVER['REDIRECT_URL']) AND $_SERVER['REQUEST_URI'])
    {
        $url = $_SERVER['REQUEST_URI'];
    }

    //Si no tenemos url para trabajar, regresamos falso
    if(! isset($url) OR !$url) return FALSE;


    //Convertimos en array para trabajar con el último
    //No tenemeos un mayor control porque sólo se trabajarán con los archivos que esten en el directorio base
    //@TODO Permitir archivos dentro de directorios
    $url = explode('/', $url);

    $uri = end($url);

    //Separamos los atributos de la image
    $img = explode('?', $uri);

    return $img[0];
}


require 'timthumb.php';


class MY_TimThumb extends timthumb{

public function __construct(){
        global $ALLOWED_SITES;
        $this->startTime = microtime(true);
        date_default_timezone_set('UTC');
        $this->debug(1, "Starting new request from " . $this->getIP() . " to " . $_SERVER['REQUEST_URI']);
        $this->calcDocRoot();

        /*
         * Agregamos el filename al cahce apra que no  muesre la primer image
         */
        $image_filename = guess_file();

        //On windows systems I'm assuming fileinode returns an empty string or a number that doesn't change. Check this.
        $this->salt = @filemtime(__FILE__) . '-' . @fileinode(__FILE__) . '-' . $image_filename;
        $this->debug(3, "Salt is: " . $this->salt);
        if(FILE_CACHE_DIRECTORY){
            if(! is_dir(FILE_CACHE_DIRECTORY)){
                @mkdir(FILE_CACHE_DIRECTORY);
                if(! is_dir(FILE_CACHE_DIRECTORY)){
                    $this->error("Could not create the file cache directory.");
                    return false;
                }
            }
            $this->cacheDirectory = FILE_CACHE_DIRECTORY;
            if (!touch($this->cacheDirectory . '/index.html')) {
                $this->error("Could not create the index.html file - to fix this create an empty file named index.html file in the cache directory.");
            }
        } else {
            $this->cacheDirectory = sys_get_temp_dir();
        }
        //Clean the cache before we do anything because we don't want the first visitor after FILE_CACHE_TIME_BETWEEN_CLEANS expires to get a stale image. 
        $this->cleanCache();

        /*
         * Buscamos la imagen de otra forma
         */
//        $this->myHost = preg_replace('/^www\./i', '', $_SERVER['HTTP_HOST']);
//        $this->src = $this->param('src');
//        $this->url = parse_url($this->src);
//        $this->src = preg_replace('/https?:\/\/(?:www\.)?' . $this->myHost . '/i', '', $this->src);
        $this->src = IMAGES_SOURCE_FOLDER . $image_filename;
        
        if(strlen($this->src) <= 3){
            $this->error("No image specified");
            return false;
        }
        if(BLOCK_EXTERNAL_LEECHERS && array_key_exists('HTTP_REFERER', $_SERVER) && (! preg_match('/^https?:\/\/(?:www\.)?' . $this->myHost . '(?:$|\/)/i', $_SERVER['HTTP_REFERER']))){
            // base64 encoded red image that says 'no hotlinkers'
            // nothing to worry about! :)
            $imgData = base64_decode("R0lGODlhUAAMAIAAAP8AAP///yH5BAAHAP8ALAAAAABQAAwAAAJpjI+py+0Po5y0OgAMjjv01YUZ\nOGplhWXfNa6JCLnWkXplrcBmW+spbwvaVr/cDyg7IoFC2KbYVC2NQ5MQ4ZNao9Ynzjl9ScNYpneb\nDULB3RP6JuPuaGfuuV4fumf8PuvqFyhYtjdoeFgAADs=");
            header('Content-Type: image/gif');
            header('Content-Length: ' . sizeof($imgData));
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header("Pragma: no-cache");
            header('Expires: ' . gmdate ('D, d M Y H:i:s', time()));
            echo $imgData;
            return false;
            exit(0);
        }
        if(preg_match('/^https?:\/\/[^\/]+/i', $this->src)){
            $this->debug(2, "Is a request for an external URL: " . $this->src);
            $this->isURL = true;
        } else {
            $this->debug(2, "Is a request for an internal file: " . $this->src);
        }
        if($this->isURL && (! ALLOW_EXTERNAL)){
            $this->error("You are not allowed to fetch images from an external website.");
            return false;
        }
        if($this->isURL){
            if(ALLOW_ALL_EXTERNAL_SITES){
                $this->debug(2, "Fetching from all external sites is enabled.");
            } else {
                $this->debug(2, "Fetching only from selected external sites is enabled.");
                $allowed = false;
                foreach($ALLOWED_SITES as $site){
                    if ((strtolower(substr($this->url['host'],-strlen($site)-1)) === strtolower(".$site")) || (strtolower($this->url['host'])===strtolower($site))) {
                        $this->debug(3, "URL hostname {$this->url['host']} matches $site so allowing.");
                        $allowed = true;
                    }
                }
                if(! $allowed){
                    return $this->error("You may not fetch images from that site. To enable this site in timthumb, you can either add it to \$ALLOWED_SITES and set ALLOW_EXTERNAL=true. Or you can set ALLOW_ALL_EXTERNAL_SITES=true, depending on your security needs.");
                }
            }
        }

        $cachePrefix = ($this->isURL ? '_ext_' : '_int_');
        if($this->isURL){
            $arr = explode('&', $_SERVER ['QUERY_STRING']);
            asort($arr);
            $this->cachefile = $this->cacheDirectory . '/' . FILE_CACHE_PREFIX . $cachePrefix . md5($this->salt . implode('', $arr) . $this->fileCacheVersion) . FILE_CACHE_SUFFIX;
        } else {
            $this->localImage = $this->getLocalImagePath($this->src);
            if(! $this->localImage){
                $this->debug(1, "Could not find the local image: {$this->localImage}");
                $this->error("Could not find the internal image you specified.");
                $this->set404();
                return false;
            }
            $this->debug(1, "Local image path is {$this->localImage}");
            $this->localImageMTime = @filemtime($this->localImage);
            //We include the mtime of the local file in case in changes on disk.
            $this->cachefile = $this->cacheDirectory . '/' . FILE_CACHE_PREFIX . $cachePrefix . md5($this->salt . $this->localImageMTime . $_SERVER ['QUERY_STRING'] . $this->fileCacheVersion) . FILE_CACHE_SUFFIX;
        }
        $this->debug(2, "Cache file is: " . $this->cachefile);

        return true;
    }

    public static function start(){
        $tim = new MY_TimThumb();
        $tim->handleErrors();
        $tim->securityChecks();
        if($tim->tryBrowserCache()){
            exit(0);
        }
        $tim->handleErrors();
        if(FILE_CACHE_ENABLED && $tim->tryServerCache()){
            exit(0);
        }
        $tim->handleErrors();
        $tim->run();
        $tim->handleErrors();
        exit(0);
    }
}


MY_Timthumb::start();
