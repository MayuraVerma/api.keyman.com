<?php  
  require_once('common.php');

  // http://www-01.sil.org/iso639-3/iso-639-3.tab <-- for iso639-3 -> iso639-1 mappings
  // http://www-01.sil.org/iso639-3/iso-639-3_Name_Index.tab <-- for language name index
  // https://www.iana.org/assignments/language-subtag-registry <-- for language subtag registry
  // Todo: filter pejorative names
  
  define('URI_LANGUAGE_SUBTAG_REGISTRY', 'https://www.iana.org/assignments/language-subtag-registry');
  define('URI_ISO639_3_TAB', 'http://www-01.sil.org/iso639-3/iso-639-3.tab');
  define('URI_ISO639_3_NAME_INDEX_TAB', 'http://www-01.sil.org/iso639-3/iso-639-3_Name_Index.tab');
  define('ETHNOLOGUE_LANGUAGE_CODES_TAB', 'https://www.ethnologue.com/sites/default/files/LanguageCodes.tab');
  define('ETHNOLOGUE_COUNTRY_CODES_TAB', 'https://www.ethnologue.com/sites/default/files/CountryCodes.tab');
  define('ETHNOLOGUE_LANGUAGE_INDEX_TAB', 'https://www.ethnologue.com/sites/default/files/LanguageIndex.tab');
  
  class build_sql_standards_data {
    private $script_path;
    private $force;

    function execute($data_root, $do_force) {
      $this->script_path = $data_root;
      $this->force = $do_force;
  
      if(!is_dir($this->script_path)) {
        mkdir($this->script_path, 0777, true) || fail("Unable to create folder {$this->script_path}");
      }
  
      if(($v = $this->build_sql_data_script_subtags()) === FALSE) {
        fail("Failed to build language subtag registry sql");
      }
      
      file_put_contents($this->script_path . "language-subtag-registry.sql", $v) || fail("Unable to write language-subtag-registry.sql to {$this->script_path}");

      if(!$this->cache_iso639_3_file(URI_ISO639_3_TAB, 'iso639-3.tab', 'iso639-3.sql', 't_iso639_3')) {
        fail("Failed to download iso639-3.tab");
      }
      
      if(!$this->cache_iso639_3_file(URI_ISO639_3_NAME_INDEX_TAB, 'iso639-3_Name_Index.tab', 'iso639-3-name-index.sql', 't_iso639_3_names')) {
        fail("Failed to download iso639-3.tab");
      }

      if(!$this->cache_ethnologue_language_index(ETHNOLOGUE_LANGUAGE_CODES_TAB, 'ethnologue_language_codes.tab', 'ethnologue_language_codes.sql', 't_ethnologue_language_codes')) {
        fail("Failed to download ethnologue_language_codes.tab");
      }

      if(!$this->cache_ethnologue_language_index(ETHNOLOGUE_COUNTRY_CODES_TAB, 'ethnologue_country_codes.tab', 'ethnologue_country_codes.sql', 't_ethnologue_country_codes')) {
        fail("Failed to download ethnologue_country_codes.tab");
      }

      if(!$this->cache_ethnologue_language_index(ETHNOLOGUE_LANGUAGE_INDEX_TAB, 'ethnologue_language_index.tab', 'ethnologue_language_index.sql', 't_ethnologue_language_index')) {
        fail("Failed to download ethnologue_language_index.tab");
      }
      
      return true;
    }
    
    /*
     Downloads an Ethnologue file and builds a script to import it.
    */
    
    function cache_ethnologue_language_index($url, $tabfilename, $sqlfilename, $table) {
      return $this->cache_tab_delimited_data($url, $tabfilename, $sqlfilename, $table);
    }
    
    /*
     Downloads an ISO639-3 file and builds a script to import it.
    */
  
    function cache_iso639_3_file($url, $tabfilename, $sqlfilename, $table) {
      return $this->cache_tab_delimited_data($url, $tabfilename, $sqlfilename, $table);
    }
    
    function cache_tab_delimited_data($url, $tabfilename, $sqlfilename, $table) {
      $cache_file = $this->script_path . $tabfilename;
      if(!cache($url, $cache_file, 60 * 60 * 24 * 7, $this->force)) {
        return false;
      }

      $cache_file = basename($cache_file);
      
      $path = str_replace('\\', '/', $this->script_path);
            
      $sql = <<<END
        load data local infile '{$path}$cache_file' 
          into table $table 
          lines terminated by '\\r\\n' 
          ignore 1 lines;
END;

      file_put_contents($this->script_path . $sqlfilename, $sql) || fail("Unable to write $sqlfilename to {$this->script_path}");
      return true;
    }
  
    private $languages = array();
    private $scripts = array();
    private $regions = array();

    /**
      Build a SQL script to insert language-subtag-registry data into the database
    */
    function build_sql_data_script_subtags() {
      $cache_file = $this->script_path . "language-subtag-registry";
      if(!cache(URI_LANGUAGE_SUBTAG_REGISTRY, $cache_file, 60 * 60 * 24 * 7, $this->force)) {
        return false;
      }
            
      if(($file = file($cache_file, FILE_IGNORE_NEW_LINES)) === FALSE) {
        return false;
      }
      if(($file = $this->unwrap($file)) === FALSE) {
        return false;
      }
      if(!$this->process_subtag_file($file)) {
        return false;
      }
      
      return
        $this->generate_language_inserts() .
        $this->generate_language_index_inserts() .
        $this->generate_script_inserts() .
        $this->generate_region_inserts();
    }
    
    /**
      language-subtag-registry wraps long lines with a two-space prefix on 
      subsequent lines. So easiest to unwrap those lines before processing.
    */
    function unwrap($array) {
      $p = '';
      for($i = sizeof($array)-1; $i >= 0; $i--) {
        if(substr($array[$i], 0, 2) == '  ') {
          $p = substr($array[$i], 2, 1024) . ' ' . trim($p);
          $array[$i] = 'WRAP:'.$array[$i];
        } elseif(!empty($p)) {
          $array[$i] .= ' ' . trim($p);
          $p = '';
        }
      }
      return $array;
    }
    
    /**
      Loads the entries we are interested in from the language-subtag-registry
      into arrays for processing.
    */
    function process_subtag_file($file) {
      $row = array();
      foreach($file as $line) {
        $line = trim($line);
        if($line == '%%') {
          if(!empty($row)) $this->process_entry($row); 
          $row = array();
          continue;
        }
        if($line == '') continue;
        
        $v = explode(':', $line);
        $id = $v[0]; $v = trim($v[1]);
        if(array_key_exists($id, $row)) {
          $this->to_array($row, $id);
          array_push($row[$id], $v);
        } else {
          $row[$id] = $v;
        }
      }
      
      return true;
    }
    
    /**
      Processes a single entry, as delimited by %% in the language-subtag-registry,
      and adds it to the appropriate array. At this time, we are only interested in
      the subtag and the description(s) for the given entry.
    */
    function process_entry($row) {
      if(!isset($row['Type'])) return;
      $this->to_array($row, 'Description');
      if($row['Description'][0] == 'Private use') {
        // no 'scope' set for script, region private use descriptive subtags
        // We don't want the "private use" subtags as they are a range rather than
        // a single subtag
        return; 
      }
      if(isset($row['Scope']) && $row['Scope'] == 'private-use') return;
      
      switch($row['Type']) {
      case 'language':
        $this->languages[$row['Subtag']] = $row['Description'];
        break;
      case 'script':
        $this->scripts[$row['Subtag']] = $row['Description'];
        break;
      case 'region':
        $this->regions[$row['Subtag']] = $row['Description'];
        break;
      }
    }
  
    /**
      Generate an SQL script to insert entries in to the t_language table
    */
    function generate_language_inserts() {
      $result = "INSERT t_language (language_id) VALUES";
      
      $comma='';
      foreach($this->languages as $lang => $detail) {
        $result .= "$comma\n  ({$this->sqlv($lang)})";
        $comma=',';
      }
      return $result . ";\n";
    }

    /**
      Generate an SQL script to insert entries in to the t_language_index table
    */
    function generate_language_index_inserts() {
      $result = "INSERT t_language_index (language_id, name) VALUES";
      
      $comma='';
      foreach($this->languages as $lang => $detail) {
        foreach($detail as $name) {
          $result .= "$comma\n  ({$this->sqlv($lang)},{$this->sqlv($name)})";
          $comma=',';
        }
      }
      return $result . ";\n";
    }

    /**
      Generate an SQL script to insert entries in to the t_script table
    */
    function generate_script_inserts() {
      $result = "INSERT t_script (script_id, name) VALUES";
      
      $comma='';
      foreach($this->scripts as $script => $detail) {
        $result .= "$comma\n  ({$this->sqlv($script)},{$this->sqlv($detail[0])})";
        $comma=',';
      }
      return $result . ";\n";
    }
    
    /**
      Generate an SQL script to insert entries in to the t_region table
    */
    function generate_region_inserts() {
      $result = "INSERT t_region (region_id, name) VALUES";
      
      $comma='';
      foreach($this->regions as $region => $detail) {
        $result .= "$comma\n  ({$this->sqlv($region)},{$this->sqlv($detail[0])})";
        $comma=',';
      }
      return $result . ";\n";
    }

    /**
      Helper function to convert a value into an array if it isn't already
    */
    function to_array(&$row, $id) {
      if(!is_array($row[$id])) $row[$id] = array($row[$id]);
    }
  
    /**
      Safe-quotes a SQL string
    */
    function sqlv($s) {
      if($s === null) return 'null';
      
      $v = strpos($s, "\0");
      if($v !== FALSE) {
        $s = substr($s, 0, strpos($s, "\0"));
      }
      $s = iconv("UTF-8", "UTF-8//IGNORE", $s); // Strip invalid UTF-8 characters
      return "'" . str_replace("'", "''", $s) . "'";
    }
  }
?>