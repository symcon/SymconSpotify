<?

	class Spotify extends IPSModule {
		
		//This one needs to be available on our OAuth client backend.
		//Please contact us to register for an identifier: https://www.symcon.de/kontakt/#OAuth
		private $oauthIdentifer = "spotify";
		
		public function Create() {
			//Never delete this line!
			parent::Create();
			
			$this->RegisterPropertyString("Token", "");

			$this->RegisterAttributeString("Favorites", "[]");
			$this->RegisterAttributeString("DeviceIDs", "[]");

			$profileName = "Favorites.Spotify." . $this->InstanceID;
			if (!IPS_VariableProfileExists($profileName)) {
				IPS_CreateVariableProfile($profileName, 1); // Integer
			}

			$this->RegisterVariableInteger("Play", $this->Translate("Play"), $profileName, 0);
			$this->EnableAction("Play");

		}
	
		public function ApplyChanges() {
			//Never delete this line!
			parent::ApplyChanges();
			
			$this->RegisterOAuth($this->oauthIdentifer);

			// The following updates won't work or make no sense if there is no token yet
			if ($this->ReadPropertyString("Token") != "") {
				$this->UpdateFavoritesProfile();
				$this->UpdateDevices();
			}
		}

		public function GetConfigurationForm() {
			$form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

			foreach (["elements", "actions"] as $area) {
				if (isset($form[$area])) {
					foreach ($form[$area] as $index => $field) {
						if (isset($field["name"]) && ($field["name"] == "Favorites")) {
							$this->SendDebug("ConfigurationForm", "Found Favorites!", 0);
							$form[$area][$index]["values"] = json_decode($this->ReadAttributeString("Favorites"));
						}
					}
				}
			}

			return json_encode($form);
		}

		public function RequestAction($Ident, $Value) {
			switch ($Ident) {
				case "Play":
					$favorites = json_decode($this->ReadAttributeString("Favorites"), true);
					$this->PlayURI($favorites[$Value]["uri"]);
					SetValue($this->GetIDForIdent($Ident), $Value);
					break;

				case "Device":
					SetValue($this->GetIDForIdent($Ident), $Value);
					$currentPlay = json_decode($this->MakeRequest("GET", "https://api.spotify.com/v1/me/player"), true);
					$this->SendDebug("Current Play", json_encode($currentPlay), 0);
					$deviceIDs = json_decode($this->ReadAttributeString("DeviceIDs"), true);
					if ($currentPlay["is_playing"] && isset($deviceIDs[GetValue($this->GetIDForIdent("Device"))])) {
						$this->MakeRequest("PUT", "https://api.spotify.com/v1/me/player", json_encode([
							"device_ids" => [
								$deviceIDs[GetValue($this->GetIDForIdent("Device"))]
							]
						]));
					}
					break;

			}
		}

		private function GetFormFieldByName($Form, $Name) {
			foreach (["elements", "actions"] as $area) {
				if (isset($form[$area])) {
					foreach ($form[$area] as $field) {
						if ($field["name"] == $Name) {
							return $field;
						}
					}
				}
			}
		}
		
		private function RegisterOAuth($WebOAuth) {
			$ids = IPS_GetInstanceListByModuleID("{F99BF07D-CECA-438B-A497-E4B55F139D37}");
			if(sizeof($ids) > 0) {
				$clientIDs = json_decode(IPS_GetProperty($ids[0], "ClientIDs"), true);
				$found = false;
				foreach($clientIDs as $index => $clientID) {
					if($clientID['ClientID'] == $WebOAuth) {
						if($clientID['TargetID'] == $this->InstanceID)
							return;
						$clientIDs[$index]['TargetID'] = $this->InstanceID;
						$found = true;
					}
				}
				if(!$found) {
					$clientIDs[] = Array("ClientID" => $WebOAuth, "TargetID" => $this->InstanceID);
				}
				IPS_SetProperty($ids[0], "ClientIDs", json_encode($clientIDs));
				IPS_ApplyChanges($ids[0]);
			}
		}
	
		/**
		* This function will be called by the register button on the property page!
		*/
		public function Register() {
			
			//Return everything which will open the browser
			return "https://oauth.ipmagic.de/authorize/".$this->oauthIdentifer."?username=".urlencode(IPS_GetLicensee());
			
		}
		
		private function FetchRefreshToken($code) {
			
			$this->SendDebug("FetchRefreshToken", "Use Authentication Code to get our precious Refresh Token!", 0);
			
			//Exchange our Authentication Code for a permanent Refresh Token and a temporary Access Token
			$options = array(
				'http' => array(
					'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
					'method'  => "POST",
					'content' => http_build_query(Array("code" => $code))
				)
			);
			$context = stream_context_create($options);
			$result = file_get_contents("https://oauth.ipmagic.de/access_token/".$this->oauthIdentifer, false, $context);

			$data = json_decode($result);
			
			if(!isset($data->token_type) || $data->token_type != "Bearer") {
				die("Bearer Token expected");
			}
			
			//Save temporary access token
			$this->FetchAccessToken($data->access_token, time() + $data->expires_in);

			//Return RefreshToken
			return $data->refresh_token;

		}
		
		/**
		* This function will be called by the OAuth control. Visibility should be protected!
		*/
		protected function ProcessOAuthData() {

			//Lets assume requests via GET are for code exchange. This might not fit your needs!
			if($_SERVER['REQUEST_METHOD'] == "GET") {
		
				if(!isset($_GET['code'])) {
					die("Authorization Code expected");
				}
				
				$token = $this->FetchRefreshToken($_GET['code']);
				
				$this->SendDebug("ProcessOAuthData", "OK! Let's save the Refresh Token permanently", 0);

				IPS_SetProperty($this->InstanceID, "Token", $token);
				IPS_ApplyChanges($this->InstanceID);
			
			} else {
				
				//Just print raw post data!
				echo file_get_contents("php://input");
				
			}

		}
		
		private function FetchAccessToken($Token = "", $Expires = 0) {
			
			//Exchange our Refresh Token for a temporary Access Token
			if($Token == "" && $Expires == 0) {
				
				//Check if we already have a valid Token in cache
				$data = $this->GetBuffer("AccessToken");
				if($data != "") {
					$data = json_decode($data);
					if(time() < $data->Expires) {
						$this->SendDebug("FetchAccessToken", "OK! Access Token is valid until ".date("d.m.y H:i:s", $data->Expires), 0);
						return $data->Token;
					}
				}

				$this->SendDebug("FetchAccessToken", "Use Refresh Token to get new Access Token!", 0);

				//If we slipped here we need to fetch the access token
				$options = array(
					"http" => array(
						"header" => "Content-Type: application/x-www-form-urlencoded\r\n",
						"method"  => "POST",
						"content" => http_build_query(Array("refresh_token" => $this->ReadPropertyString("Token")))
					)
				);
				$context = stream_context_create($options);
				$result = file_get_contents("https://oauth.ipmagic.de/access_token/".$this->oauthIdentifer, false, $context);

				$data = json_decode($result);
				
				if(!isset($data->token_type) || $data->token_type != "Bearer") {
					die("Bearer Token expected");
				}
				
				//Update parameters to properly cache it in the next step
				$Token = $data->access_token;
				$Expires = time() + $data->expires_in;
				
				//Update Refresh Token if we received one! (This is optional)
				if(isset($data->refresh_token)) {
					$this->SendDebug("FetchAccessToken", "NEW! Let's save the updated Refresh Token permanently", 0);

					IPS_SetProperty($this->InstanceID, "Token", $data->refresh_token);
					IPS_ApplyChanges($this->InstanceID);
				}
				
				
			}

			$this->SendDebug("FetchAccessToken", "CACHE! New Access Token is valid until " . date("d.m.y H:i:s", $Expires), 0);
			
			//Save current Token
			$this->SetBuffer("AccessToken", json_encode(Array("Token" => $Token, "Expires" => $Expires)));
			
			//Return current Token
			return $Token;
			
		}
		
		private function MakeRequest($method, $url, $body = "") {

			$header = "Authorization: Bearer " . $this->FetchAccessToken() . "\r\n" . "Content-Type: application/json";
			if (in_array($method, ["POST", "PUT"])) {
				$header .= "\r\n" . "Content-Length: " . strlen($body);
			}
			
			$opts = array(
			  "http"=>array(
				"method" => $method,
				"header" => $header
			  )
			);

			if ($body != "") {
				$opts["http"]["content"] = $body;
			}

			$this->SendDebug("Request URL", $url, 0);
			$this->SendDebug("Request Options", json_encode($opts), 0);
			$context = stream_context_create($opts);
			
			return file_get_contents($url, false, $context);	
		}

		private function UpdateFavoritesProfile() {
			$profileName = "Favorites.Spotify." . $this->InstanceID;
			$profile = IPS_GetVariableProfile($profileName);

			// Delete all current associations
			foreach ($profile["Associations"] as $association) {
				IPS_SetVariableProfileAssociation($profileName, $association["Value"], "", "", -1);
			}

			$favorites = json_decode($this->ReadAttributeString("Favorites"), true);
			foreach($favorites as $index => $favorite) {
				// Get proper name, depending on type
				$name = "";
				switch ($favorite["type"]) {
					case $this->Translate("Album"):
					case $this->Translate("Playlist"):
						$name = $favorite["albumPlaylist"];
						break;

					case $this->Translate("Artist"):
						$name = $favorite["artist"];
						break;

					case $this->Translate("Track"):
						$name = $favorite["track"];
						break;
				}
				IPS_SetVariableProfileAssociation($profileName, $index, $favorite["type"] . ": " . $name, "", -1);
			}
		}

		private function UpdateDevices() {
			$profileName = "Devices.Spotify";
			if (!IPS_VariableProfileExists($profileName)) {
				IPS_CreateVariableProfile($profileName, 1);
			}

			$profile = IPS_GetVariableProfile($profileName);
			// Delete all current associations
			foreach ($profile["Associations"] as $association) {
				IPS_SetVariableProfileAssociation($profileName, $association["Value"], "", "", -1);
			}

			$devices = json_decode($this->MakeRequest("GET", "https://api.spotify.com/v1/me/player/devices"), true);
			$deviceIDs = [];

			foreach($devices['devices'] as $index => $device) {
				IPS_SetVariableProfileAssociation($profileName, $index, $device["name"], "", -1);
				$deviceIDs[$index] = $device["id"];
			}

			$this->WriteAttributeString("DeviceIDs", json_encode($deviceIDs));
			$this->RegisterVariableInteger("Device", $this->Translate("Device"), $profileName, 0);
			$this->EnableAction("Device");
		}
		
		public function NextTrack() {
			
			$this->MakeRequest("POST", "https://api.spotify.com/v1/me/player/next");
			
		}

		public function PlayURI(string $URI) {
			// Special handling for tracks
			$body = [];
			// It seems like URI for tracks seem to start with "spotify:track", so we'll start with that
			// If needed, we could check for track existence or something
			if (substr($URI, 0, strlen("spotify:track")) == "spotify:track") {
				$body["uris"] = [ $URI ];
			}
			else {
				$body["context_uri"] = $URI;
			}

			$deviceIDs = json_decode($this->ReadAttributeString("DeviceIDs"), true);
			$url = "https://api.spotify.com/v1/me/player/play";
			if (isset($deviceIDs[GetValue($this->GetIDForIdent("Device"))])) {
				$url .= "?device_id=" . urlencode($deviceIDs[GetValue($this->GetIDForIdent("Device"))]);
			}

			$this->MakeRequest("PUT", $url, json_encode($body));
		}

		public function PlayAlbum(string $Name) {
			$album = json_decode($this->MakeRequest("GET", "https://api.spotify.com/v1/search?q=" . urlencode($Name) . "&type=album&limit=1"), true);

			$uri = $album["albums"]["items"][0]["uri"];

			$body = [
				"context_uri" => $uri
			];

			$this->MakeRequest("PUT", "https://api.spotify.com/v1/me/player/play", json_encode($body));
		}

		public function Search(string $SearchQuery, bool $SearchAlbums, bool $SearchArtists, bool $SearchPlaylists, bool $SearchTracks) {
			$types = [];
			if ($SearchAlbums) {
				$types[] = "album";
			}
			if ($SearchArtists) {
				$types[] = "artist";
			}
			if ($SearchPlaylists) {
				$types[] = "playlist";
			}
			if ($SearchTracks) {
				$types[] = "track";
			}

			if (count($types) == 0) {
				throw new Exception($this->Translate("No types selected"));
			}
			$results = json_decode($this->MakeRequest("GET", "https://api.spotify.com/v1/search?q=" . urlencode($SearchQuery) . "&type=" . implode(",", $types)), true);

			$resultList = [];

			if (isset($results["albums"]["items"])) {
				foreach ($results["albums"]["items"] as $album) {
					$artists = [];
					foreach ($album["artists"] as $artist) {
						$artists[] = $artist["name"];
					}

					$resultList[] = [
						"type" => $this->Translate("Album"),
						"artist" => implode(", ", $artists),
						"albumPlaylist" => $album["name"],
						"track" => "-",
						"uri" => $album["uri"]
					];
				}
			}

			if (isset($results["artists"]["items"])) {
				foreach ($results["artists"]["items"] as $artist) {
					$resultList[] = [
						"type" => $this->Translate("Artist"),
						"artist" => $artist["name"],
						"albumPlaylist" => "-",
						"track" => "-",
						"uri" => $artist["uri"]
					];
				}
			}

			if (isset($results["playlists"]["items"])) {
				foreach ($results["playlists"]["items"] as $playlist) {
					$resultList[] = [
						"type" => $this->Translate("Playlist"),
						"artist" => $playlist["owner"]["display_name"],
						"albumPlaylist" => $playlist["name"],
						"track" => "-",
						"uri" => $playlist["uri"]
					];
				}
			}

			if (isset($results["tracks"]["items"])) {
				foreach ($results["tracks"]["items"] as $track) {
					$artists = [];
					foreach ($track["artists"] as $artist) {
						$artists[] = $artist["name"];
					}

					$resultList[] = [
						"type" => $this->Translate("Track"),
						"artist" => implode(", ", $artists),
						"albumPlaylist" => $track["album"]["name"],
						"track" => $track["name"],
						"uri" => $track["uri"]
					];
				}
			}

			$this->UpdateFormField("SearchResults", "values", json_encode($resultList));
			$this->UpdateFormField("SearchResults", "rowCount", 20);
		}

		public function AddToFavorites($favorite) {
			$favorites = json_decode($this->ReadAttributeString("Favorites"));
			$favorites[] = $favorite;
			$this->WriteAttributeString("Favorites", json_encode($favorites));
			$this->UpdateFormField("Favorites", "values", json_encode($favorites));
			$this->UpdateFavoritesProfile();
		}
		
	}

?>
