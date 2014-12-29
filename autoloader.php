<?php
/**
 * Autoloader class with file cashing
 */


class Autoloader
{
    const IS_DEBUG = false;
    
    static private $autoloader;
    
    /**
     * @var array   absolute paths to the places where classes are loaded
     */
    private $dirPaths = array();

    /**
     * @var array   class prefixes (as key) and absolute paths had loaded classes (as value)
     */
    private $classPaths;

    /**
     * @var array   missing scripts (paths) of classes - used by debug
     */
    private $missingScripts = array();

    /**
     * @var bool    determines whether the autoloader is the last autoloader in the SPL
     */
    private $isLastLoaderSPL;
  
    /**
     * @var string
     */
    private $fileName;
    
    /**
     * @var array data 
     */
    private $fileData;
    
    private $prePaths = array();
    private $isIncludePath;
    
    

    
    /**
     * The function enabled automatic load classes
     * @param string|array|null $prePaths  one prefix (as string) or many prefixes (as array)
     * @param bool $isIncludePath  if you want to read paths from include_path (Enviroment)
     */
    static public function startAutoload($prePaths='', $isIncludePath=false)
    {
        if (!isset(self::$autoloader))
        {
            self::$autoloader = new self($prePaths, $isIncludePath);
        }
    }
    
    
    
    

    /**
     * @param string|array|null $prePaths  one prefix (as string) or many prefixes (as array)
     * @param bool $isIncludePath   if you want to read paths from include_path (Enviroment)
     */
    public function __construct($prePaths='', $isIncludePath=false)
    {
        spl_autoload_register(array($this, 'autoload'));
        
        if(!$prePaths){$prePaths=__DIR__;}
        $this->prePaths=$prePaths;
        $this->isIncludePath=$isIncludePath;

        
        #Read cashe
        $file=pathinfo(__FILE__);
        $file=DIR_TEMP . $file['filename']. '.dat';
        $this->fileName = $file;
        if(file_exists($file)){
            $fileData=unserialize(file_get_contents($file));
            $this->fileData = $fileData;
            if($fileData['dirPaths']){$this->dirPaths = $fileData['dirPaths'];}
            if($fileData['classPaths']){$this->classPaths = $fileData['classPaths'];}
        }
        
    }


    /**
     * The function implements loading classes
     * @param string    $class  class name
     * @return bool     success
     */
    public function autoload($class)
    {
        $prefixClass = strstr($class, '\\', true);
        $className = str_replace('\\', DIRECTORY_SEPARATOR, $class).'.php';

        
        
        if(!$prefixClass){$prefixClass=$class;}

        if (isset($this->classPaths[$prefixClass]))
        {
            #Use cashed paths for class
            if ($this->classPaths[$prefixClass] === false) return false;

            if ((@include $this->classPaths[$prefixClass].$className) !== false) return true;
            else
            {
                $this->throwWarning(
                    "Problem with the class <b>$class</b>, classPrefix <b>'$prefixClass'</b>"
                    ." has wrong path <b>{$this->classPaths[$prefixClass]}</b>"
                );
                unset($this->classPaths[$prefixClass]);
                return $this->autoload($class);
            }
        }
        else
        {
            #Seek path for class
            if($this->prePaths) {
                $this->buildPaths();
                $this->prePaths = false;
            }
            
            foreach ($this->dirPaths as $dirPath)
            {
                if ((@include $dirPath.$class.DIRECTORY_SEPARATOR.$className) !== false)
                {
                    $this->classPaths[$prefixClass] = $dirPath.$class.DIRECTORY_SEPARATOR;
                    return true;
                }elseif((@include $dirPath.$className) !== false)
                {
                    $this->classPaths[$prefixClass] = $dirPath;
                    return true;
                }

                $this->missingScripts[$dirPath][] = $dirPath.$className;
            }

        }


        #Action for unloaded classes
        if (!isset($this->isLastLoaderSPL))
        {
            $splLoaders = spl_autoload_functions();
            $lastSpl = end($splLoaders);

            if (is_array($lastSpl) && $lastSpl[0] === $this)
            {
                $this->throwWarning(
                    "Problem with loading the class <b>$className</b>"
                    ." (classPrefix <b>".($prefixClass?:'false')."</b>)"
                );
            }
            else
                $this->isLastLoaderSPL = false;
        }

        return false;
    }
    
    private function buildPaths(){
        
        $dirPaths = (array) $this->prePaths;

        if ($this->isIncludePath)
        {
            $includePath = get_include_path();
            if ($includePath !== '')
            {
                foreach (explode(PATH_SEPARATOR, $includePath) as $p)
                {
                    if ($p !== '.') $dirPaths[] = $p;
                }
            }
        }
        
        //--------------------------

        foreach ($dirPaths as $key => $prePath)
        {
            $absolutePath = stream_resolve_include_path($prePath);
            if ($absolutePath === false)
            {
                $this->throwWarning("The wrong prefix autoloader: <b>'$prePath'</b>");
                continue;
            }
            
            $absolutePath .= DIRECTORY_SEPARATOR;
            if (is_string($key))
            {
                if ($key === '') $key = false;
                $this->classPaths[$key] = $absolutePath;
            }
            else
            {
                $this->dirPaths[] = $absolutePath;
            }
            
        }

        $this->dirPaths=array_unique($this->dirPaths);         
        
    }


    public function __destruct()
    {
        #Write cashe
        $fileData = array();
        if($this->dirPaths){$fileData['dirPaths'] = $this->dirPaths;}
        if($this->classPaths){$fileData['classPaths'] = $this->classPaths;}
        
        if($fileData != $this->fileData){
            file_put_contents($this->fileName, serialize($fileData));
        }
        
        if (self::IS_DEBUG)
        {
            $this->showDebugInfo();
        }
    }

    


    
    private function throwWarning($message)
    {
        $error_handler = function ($level, $message, $file, $line, $context)
        {
            $debug = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $debug = (object) $debug[2];
            echo "<b>Warning</b>:&nbsp;&nbsp;&nbsp;&nbsp;$message&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;in <b>$debug->file</b> on line <b>$debug->line</b><br />";
            file_put_contents('php://stderr', "Warning:    ".strip_tags($message)."\t    in $debug->file on line $debug->line\n");
        };
//        set_error_handler($error_handler);
//        trigger_error($message, E_USER_WARNING);
//        restore_error_handler();
    }

    private function showDebugInfo()
    {
        echo '<pre>----------------------------------------------------------</pre>';
        echo 'OBJECT AUTOLOAD:';
        echo '<pre>'.print_r($this, true).'</pre>';

        echo '<pre>----------------------------------------------------------</pre>';
        echo 'MISSING SCRIPTS: <br /><br />';
        $i = 0;
        foreach ($this->missingScripts as $dirPath=> $missingScripts)
        {
            echo 'Prefix autoloader: \''.$dirPath.'\'<br />';
            foreach ($missingScripts as $m)
            {
                echo '<pre>'.print_r(++$i.'. '.$m, true).'</pre>';
            }
        }
    }
}
