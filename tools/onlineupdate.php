<?php
  require_once('util.php');

  //TODO: LogToGoogleAnalytics()....
  
  class OnlineUpdate {
    private $platform, $installerRegex;
    
    public function __construct($platform, $installerRegex) {
      $this->platform = $platform;
      $this->installerRegex = $installerRegex;
    }
    
    public function execute() {
      json_response();
      allow_cors();

      if(!isset($_REQUEST['Version'])) {
        /* Invalid update check */
        fail('Invalid Parameters - expected Version parameter', 401);
      }
  
      /* Valid update check */
      $appVersion = $_REQUEST['Version'];
        
      $desktop_update = [];
      
      $desktop_update[$this->platform] = $this->BuildKeymanDesktopVersionResponse($appVersion);
      if(!empty($desktop_update[$this->platform])) {
        $newAppVersion = $desktop_update[$this->platform]->version;
      } else {
        $newAppVersion = $appVersion;
      }
      $desktop_update['keyboards'] = $this->BuildKeyboardsResponse($newAppVersion);
  
      echo json_encode($desktop_update, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
  
    private function BuildKeymanDesktopVersionResponse($InstalledVersion) {
      $platform = $this->platform;
      
      $DownloadVersions = @json_decode(file_get_contents(get_site_url_downloads() . "/api/version/$this->platform/2.0"));
      if($DownloadVersions === NULL) {
        fail('Unable to retrieve version data from '.get_site_url_downloads(), 500);
      }
      
      if(!isset($DownloadVersions->$platform)) {
        fail("Unable to find {$platform} key in ".get_site_url_downloads()." data", 500);
      }
      
      // Check each of the tiers for the one that matches the major version.
      // This gets us upgrades on alpha, beta and stable tiers.
      $tiers = get_object_vars($DownloadVersions->$platform);
      foreach($tiers as $tier => $tierdata) {
        if($this->IsSameMajorVersion($tierdata->version, $InstalledVersion)) {
          // TODO: We offer upgrades for MAJOR.x.x.x versions. We want users to stay on the
          // same tier. Note, if we decide to do an alpha release for a minor version update then
          // we may have a problem: right now  there would be no way to tell if the user is on 
          // an alpha, beta or stable tier except by looking up the version in downloads.keyman.com 
          // (which is not currently supported, but would be easy enough to support).
          
          $files = get_object_vars($tierdata->files);
          foreach($files as $file => $filedata) {
            // This is currently tied to Windows -- for other platforms we need to change this
            if(preg_match($this->installerRegex, $file)) {
              $filedata->url = get_site_url_downloads() . "/$platform/$tier/{$filedata->version}/{$file}";
              return $filedata;
            }
          }
        }
      }
      return FALSE;
    }
  
    private function IsSameMajorVersion($v1, $v2) {
      if(empty($v1) || empty($v2)) return FALSE;
      $v1 = explode('.', $v1);
      $v2 = explode('.', $v2);
      return $v1[0] == $v2[0];
    }
  
    private function BuildKeyboardsResponse($appVersion) {
      $keyboards = [];
      
      // For each keyboard in the parameter request, check for a new version or 
      // for a keyboard that replaces it
      
      foreach ($_REQUEST as $id => $version) {
        while(is_array($version)) {
          $version = array_shift($version);
        }
        
        if(substr($id, 0, 8) == 'Package_')	{
          $PackageID = iconv("CP1252", "UTF-8", substr($id, 8, strlen($id)));
          $keyboard = $this->BuildKeyboardResponse($PackageID, $version, $appVersion);
          if($keyboard !== FALSE) {
            $keyboards[$PackageID] = $keyboard;
          }
        }
      }
      return $keyboards;
    }
    
    private function BuildKeyboardResponse($id, $version, $appVersion) {
      $platform = $this->platform;
      $KeyboardDownload = json_decode(file_get_contents(get_site_url_api()."/keyboard/$id"));
      if($KeyboardDownload === NULL) {
        // not found
        return FALSE;
      }
      
      // Check if the keyboard has been replaced by something else and return it if so
      if(isset($KeyboardDownload->related)) {
        $r = get_object_vars($KeyboardDownload->related);
        foreach($r as $rid => $data) {
          if(isset($data->deprecatedBy) && $data->deprecatedBy) {
            $newData = $this->BuildKeyboardResponse($rid, '0.0', $appVersion); // 0.0 because we want to get the newest version
            if($newData === FALSE) {
              // Don't attempt to upgrade if the deprecating keyboard 
              // is not available for some reason
              break;
            }
            return $newData;
          }
        }
      }
      
      if(!isset($KeyboardDownload->version)) {
        // Invalid keyboard data
        return FALSE;
      }
      
      if(version_compare($KeyboardDownload->version, $version, '<=')) {
        // User already a newer version of the keyboard installed
        return FALSE;
      }
      
      if(!isset($KeyboardDownload->platformSupport->$platform) || $KeyboardDownload->platformSupport->$platform == 'none') {
        // Doesn't run on Windows / "$platform" (this could in theory happen with a deprecation keyboard)
        return FALSE;
      }
      
      if(isset($KeyboardDownload->minKeymanVersion) && version_compare($KeyboardDownload->minKeymanVersion, $appVersion, '>')) {
        // New version of the keyboard doesn't run with the user's Keyman Desktop version
        return FALSE;
      }
      
      $KeyboardDownload->url = $this->BuildKeyboardDownloadPath($KeyboardDownload->id, $KeyboardDownload->version);
      if($KeyboardDownload->url === FALSE) {
        // Unable to build a url for the keyboard, would only happen if downloads.keyman.com was out of sync with
        // api.keyman.com
        return FALSE;
      }
      return $KeyboardDownload;
    }
    
    private function BuildKeyboardDownloadPath($id, $version) {
      $data = @json_decode(file_get_contents(get_site_url_downloads() . "/api/keyboard/$id"));
      if($data === NULL) {
        return FALSE;
      }
      if(!isset($data->kmp)) {
        return FALSE;
      }
      return $data->kmp;
    }
  }
?>