<?php

declare(strict_types=1);

    class Spotify extends IPSModule
    {
        const PREVIOUS = 0;
        const STOP = 1;
        const PLAY = 2;
        const PAUSE = 3;
        const NEXT = 4;

        const REPEAT_OFF = 0;
        const REPEAT_CONTEXT = 1;
        const REPEAT_TRACK = 2;

        const PLACEHOLDER_NONE = '-';

        //This one needs to be available on our OAuth client backend.
        //Please contact us to register for an identifier: https://www.symcon.de/kontakt/#OAuth
        private $oauthIdentifer = 'spotify';

        public function Create()
        {
            //Never delete this line!
            parent::Create();

            $this->RegisterPropertyInteger('UpdateInterval', 60);

            $this->RegisterAttributeString('Token', '');
            $this->RegisterAttributeString('Favorites', '[]');
            $this->RegisterAttributeString('DeviceIDs', '[]');

            $profileNameFavorites = 'Spotify.Favorites.' . $this->InstanceID;
            // Associations will be added later in ApplyChanges
            if (!IPS_VariableProfileExists($profileNameFavorites)) {
                IPS_CreateVariableProfile($profileNameFavorites, 1); // Integer
            }

            $profileNameDevices = 'Spotify.Devices';
            if (!IPS_VariableProfileExists($profileNameDevices)) {
                IPS_CreateVariableProfile($profileNameDevices, 1);
            }

            $this->RegisterVariableInteger('Favorite', $this->Translate('Favorite'), $profileNameFavorites, 50);
            $this->EnableAction('Favorite');

            $this->RegisterVariableInteger('Device', $this->Translate('Device'), $profileNameDevices, 50);
            $this->EnableAction('Device');

            $this->RegisterVariableInteger('Action', $this->Translate('Action'), '~PlaybackPreviousNext', 40);
            $this->EnableAction('Action');

            $profileNameRepeat = 'Spotify.Repeat';
            if (!IPS_VariableProfileExists($profileNameRepeat)) {
                IPS_CreateVariableProfile($profileNameRepeat, 1); // Integer
                IPS_SetVariableProfileAssociation($profileNameRepeat, self::REPEAT_OFF, $this->Translate('Off'), '', -1);
                IPS_SetVariableProfileAssociation($profileNameRepeat, self::REPEAT_CONTEXT, $this->Translate('Context'), '', -1);
                IPS_SetVariableProfileAssociation($profileNameRepeat, self::REPEAT_TRACK, $this->Translate('Track'), '', -1);
            }
            $this->RegisterVariableInteger('Repeat', $this->Translate('Repeat'), $profileNameRepeat, 50);
            $this->EnableAction('Repeat');

            $this->RegisterVariableBoolean('Shuffle', $this->Translate('Shuffle'), '~Switch', 50);
            $this->EnableAction('Shuffle');

            $this->RegisterVariableString('CurrentTrack', $this->Translate('Current Track'), '', 10);
            $this->RegisterVariableString('CurrentArtist', $this->Translate('Current Artist'), '', 20);
            $this->RegisterVariableString('CurrentAlbum', $this->Translate('Current Album'), '', 30);
            $this->RegisterVariableString('CurrentImage', $this->Translate('Current Image'), '~HTMLBox', 0);

            $this->RegisterTimer('UpdateTimer', 0, 'SPO_UpdateVariables($_IPS["TARGET"]);');
        }

        public function ApplyChanges()
        {
            //Never delete this line!
            parent::ApplyChanges();

            $this->RegisterOAuth($this->oauthIdentifer);

            if ($this->ReadAttributeString('Token') != '') {
                $this->UpdateFavoritesProfile();
            }

            $this->UpdateVariables();

            $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('UpdateInterval') * 1000);
        }

        public function GetConfigurationForm()
        {
            $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

            if (!$this->ReadAttributeString('Token')) {
                for ($i = 1; $i < count($form['actions']); $i++) {
                    $form['actions'][$i]['visible'] = false;
                }
            } else {
                foreach (['elements', 'actions'] as $area) {
                    if (isset($form[$area])) {
                        foreach ($form[$area] as $index => $field) {
                            if (isset($field['name']) && ($field['name'] == 'Favorites')) {
                                $form[$area][$index]['values'] = $this->GetTranslatedFavorites();
                            } elseif (isset($field['name']) && ($field['name'] == 'UserPlaylists')) {

                                // TODO: Try block as a user could not be registered properly. In that case, we want to give some meaningful feedback
                                try {
                                    $playlists = json_decode($this->MakeRequest('GET', 'https://api.spotify.com/v1/me/playlists'), true);
                                    $this->SendDebug('Playlists', json_encode($playlists), 0);
                                    $userPlaylists = [];
                                    foreach ($playlists['items'] as $playlist) {
                                        $userPlaylists[] = [
                                            'playlist' => $playlist['name'],
                                            'tracks'   => strval($playlist['tracks']['total']),
                                            'owner'    => $playlist['owner']['display_name'],
                                            'uri'      => $playlist['uri'],
                                            'add'      => $this->isFavorite($playlist['uri'])
                                        ];
                                    }
                                    $form[$area][$index]['values'] = $userPlaylists;
                                } catch (Exception $e) {
                                }
                            }
                        }
                    }
                }
            }

            return json_encode($form);
        }

        public function RequestAction($Ident, $Value)
        {
            switch ($Ident) {
                case 'Favorite':
                    $favorites = json_decode($this->ReadAttributeString('Favorites'), true);
                    $this->PlayURI($favorites[$Value]['uri']);
                    $this->SetValue($Ident, $Value);
                    $this->UpdateVariables();
                    break;

                case 'Device':
                    $this->SetValue($Ident, $Value);
                    $deviceID = $this->getCurrentDeviceID();
                    if ($this->isPlaybackActive() && $deviceID) {
                        $this->MakeRequest('PUT', 'https://api.spotify.com/v1/me/player', json_encode([
                            'device_ids' => [$deviceID]
                        ]));
                    }
                    break;

                case 'Action':
                    switch ($Value) {
                        case self::PLAY:
                            $this->Play();
                            break;

                        case self::STOP:
                        case self::PAUSE:
                            $this->Pause();
                            break;

                        case self::NEXT:
                            $this->NextTrack();
                            break;

                        case self::PREVIOUS:
                            $this->PreviousTrack();
                            break;
                    }
                    $this->UpdateVariables();
                    break;

                case 'Repeat':
                    $this->SetRepeat($Value);
                    break;

                case 'Shuffle':
                    $this->SetShuffle($Value);
                    break;

            }
        }

        /**
         * This function will be called by the register button on the property page!
         */
        public function Register()
        {

            //Return everything which will open the browser
            return 'https://oauth.ipmagic.de/authorize/' . $this->oauthIdentifer . '?username=' . urlencode(IPS_GetLicensee());
        }

        public function Play()
        {
            $currentPlay = $this->requestCurrentPlay();
            if ($currentPlay) {
                $this->MakeRequest('PUT', 'https://api.spotify.com/v1/me/player/play');
                $this->SetValue('Action', self::PLAY);
            } else {
                RequestAction($this->GetIDForIdent('Favorite'), $this->GetValue('Favorite'));
            }
        }

        public function Pause()
        {
            $currentPlay = $this->requestCurrentPlay();
            if ($this->isPlaybackActive($currentPlay)) {
                $this->MakeRequest('PUT', 'https://api.spotify.com/v1/me/player/pause');
                $this->SetValue('Action', self::PAUSE);
            }
        }

        public function PreviousTrack()
        {
            $currentPlay = $this->requestCurrentPlay();
            if ($this->isPlaybackActive($currentPlay)) {
                $this->MakeRequest('POST', 'https://api.spotify.com/v1/me/player/previous');
            }
        }

        public function NextTrack()
        {
            $currentPlay = $this->requestCurrentPlay();
            if ($this->isPlaybackActive($currentPlay)) {
                $this->MakeRequest('POST', 'https://api.spotify.com/v1/me/player/next');
            }
        }

        public function PlayURI(string $URI)
        {
            // Special handling for tracks
            $body = [];
            // It seems like URI for tracks seem to start with "spotify:track", so we'll start with that
            // If needed, we could check for track existence or something
            if (substr($URI, 0, strlen('spotify:track')) == 'spotify:track') {
                $body['uris'] = [$URI];
            } else {
                $body['context_uri'] = $URI;
            }

            $deviceID = $this->getCurrentDeviceID();
            $url = 'https://api.spotify.com/v1/me/player/play';
            if ($deviceID) {
                $url .= '?device_id=' . $deviceID;
            }

            $this->MakeRequest('PUT', $url, json_encode($body));
            $this->SetValue('Action', self::PLAY);
        }

        public function Search(string $SearchQuery, bool $SearchAlbums, bool $SearchArtists, bool $SearchPlaylists, bool $SearchTracks)
        {
            $types = [];
            if ($SearchAlbums) {
                $types[] = 'album';
            }
            if ($SearchArtists) {
                $types[] = 'artist';
            }
            if ($SearchPlaylists) {
                $types[] = 'playlist';
            }
            if ($SearchTracks) {
                $types[] = 'track';
            }

            if (count($types) == 0) {
                throw new Exception($this->Translate('No types selected'));
            }
            $results = json_decode($this->MakeRequest('GET', 'https://api.spotify.com/v1/search?q=' . urlencode($SearchQuery) . '&type=' . implode(',', $types)), true);

            $resultList = [];

            if (isset($results['albums']['items'])) {
                foreach ($results['albums']['items'] as $album) {
                    $artists = [];
                    foreach ($album['artists'] as $artist) {
                        $artists[] = $artist['name'];
                    }

                    $resultList[] = [
                        'type'          => $this->Translate('Album'),
                        'artist'        => implode(', ', $artists),
                        'albumPlaylist' => $album['name'],
                        'track'         => '-',
                        'uri'           => $album['uri']
                    ];
                }
            }

            if (isset($results['artists']['items'])) {
                foreach ($results['artists']['items'] as $artist) {
                    $resultList[] = [
                        'type'          => $this->Translate('Artist'),
                        'artist'        => $artist['name'],
                        'albumPlaylist' => '-',
                        'track'         => '-',
                        'uri'           => $artist['uri']
                    ];
                }
            }

            if (isset($results['playlists']['items'])) {
                foreach ($results['playlists']['items'] as $playlist) {
                    $resultList[] = [
                        'type'          => $this->Translate('Playlist'),
                        'artist'        => $playlist['owner']['display_name'],
                        'albumPlaylist' => $playlist['name'],
                        'track'         => '-',
                        'uri'           => $playlist['uri']
                    ];
                }
            }

            if (isset($results['tracks']['items'])) {
                foreach ($results['tracks']['items'] as $track) {
                    $artists = [];
                    foreach ($track['artists'] as $artist) {
                        $artists[] = $artist['name'];
                    }

                    $resultList[] = [
                        'type'          => $this->Translate('Track'),
                        'artist'        => implode(', ', $artists),
                        'albumPlaylist' => $track['album']['name'],
                        'track'         => $track['name'],
                        'uri'           => $track['uri']
                    ];
                }
            }

            $this->UpdateFormField('SearchResults', 'values', json_encode($resultList));
            $this->UpdateFormField('SearchResults', 'rowCount', 20);
        }

        public function AddSearchResultToFavorites($SearchResult)
        {
            if ($SearchResult['add']) {
                unset($SearchResult['add']);
                // Revert translation of type, so we save the original text
                switch ($SearchResult['type']) {
                    case $this->Translate('Album'):
                        $SearchResult['type'] = 'Album';
                        break;

                    case $this->Translate('Playlist'):
                        $SearchResult['type'] = 'Playlist';
                        break;

                    case $this->Translate('Artist'):
                        $SearchResult['type'] = 'Artist';
                        break;

                    case $this->Translate('Track'):
                        $SearchResult['type'] = 'Track';
                        break;
                }
                $this->AddToFavorites($SearchResult);
            } else {
                $this->RemoveFavorite($SearchResult['uri']);
            }
        }

        public function AddPlaylistToFavorites($Playlist)
        {
            if ($Playlist['add']) {
                $newFavorite = [
                    'type'          => $this->Translate('Playlist'),
                    'artist'        => $Playlist['owner'],
                    'albumPlaylist' => $Playlist['playlist'],
                    'track'         => '-',
                    'uri'           => $Playlist['uri']
                ];
                $this->AddToFavorites($newFavorite);
            } else {
                $this->RemoveFavorite($Playlist['uri']);
            }
        }

        public function RemoveFavorite(string $FavoriteURI)
        {
            $favorites = json_decode($this->ReadAttributeString('Favorites'), true);
            foreach ($favorites as $index => $favorite) {
                if ($favorite['uri'] == $FavoriteURI) {
                    array_splice($favorites, $index, 1);
                    $this->WriteAttributeString('Favorites', json_encode($favorites));
                    $this->UpdateFormField('Favorites', 'values', json_encode($this->GetTranslatedFavorites()));
                    $this->UpdateFavoritesProfile();
                    break;
                }
            }
        }

        public function SetRepeat(int $Repeat)
        {
            $url = 'https://api.spotify.com/v1/me/player/repeat?state=';
            switch ($Repeat) {
                case self::REPEAT_OFF:
                    $url .= 'off';
                    break;

                case self::REPEAT_CONTEXT:
                    $url .= 'context';
                    break;

                case self::REPEAT_TRACK:
                    $url .= 'track';
                    break;

            }

            $this->MakeRequest('PUT', $url);
            $this->SetValue('Repeat', $Repeat);
        }

        public function SetShuffle(bool $Shuffle)
        {
            $this->MakeRequest('PUT', 'https://api.spotify.com/v1/me/player/shuffle?state=' . json_encode($Shuffle));
            $this->SetValue('Shuffle', $Shuffle);
        }

        public function UpdateVariables()
        {
            $resetCurrentPlaying = function(bool $resetCommands = false) {
                $this->SendDebug('Reset', 'Current Playing', 0);
                $this->SetValue('CurrentTrack', self::PLACEHOLDER_NONE);
                $this->SetValue('CurrentArtist', self::PLACEHOLDER_NONE);
                $this->SetValue('CurrentAlbum', self::PLACEHOLDER_NONE);
                $this->SetValue('CurrentImage', '');
                if ($resetCommands) {
                    $this->SetValue('Action', self::PAUSE);
                    $this->SetValue('Repeat', self::REPEAT_OFF);
                    $this->SetValue('Shuffle', false);
                }
            };
            // The following updates won't work or make no sense if there is no token yet
            if ($this->ReadAttributeString('Token') != '') {
                $this->UpdateFavoritesProfile();
                $this->UpdateDevices();

                $currentPlay = $this->requestCurrentPlay();
                if ($this->isPlaybackActive($currentPlay)) {
                    $this->SetValue('Action', self::PLAY);
                    switch ($currentPlay['repeat_state']) {
                        case 'off':
                            $this->SetValue('Repeat', self::REPEAT_OFF);
                            break;

                        case 'track':
                            $this->SetValue('Repeat', self::REPEAT_TRACK);
                            break;

                        case 'context':
                            $this->SetValue('Repeat', self::REPEAT_CONTEXT);
                            break;
                    }

                    $this->SetValue('Shuffle', $currentPlay['shuffle_state']);

                    if (isset($currentPlay['item']['type'])) {
                        switch ($currentPlay['item']['type']) {
                            case 'track':
                                $this->SetValue('CurrentTrack', $currentPlay['item']['name']);
                                $artists = [];
                                foreach ($currentPlay['item']['artists'] as $artist) {
                                    $artists[] = $artist['name'];
                                }
                                $this->SetValue('CurrentArtist', implode(', ', $artists));
                                $this->SetValue('CurrentAlbum', $currentPlay['item']['album']['name']);
                                if (isset($currentPlay['item']['album']['images'][0])) {
                                    $imageObject = $currentPlay['item']['album']['images'][0];
                                    $this->SetValue('CurrentImage', '<iframe style="border: 0;" height="' . $imageObject['height'] . '" width = "' . $imageObject['width'] . '" src="' . $imageObject['url'] . '">');
                                }
                                else {
                                    $this->SetValue('CurrentImage', '');
                                }
                                break;

                            case 'episode':
                                $this->SetValue('CurrentTrack', $currentPlay['item']['name']);
                                $artists = [];
                                foreach ($currentPlay['item']['artists'] as $artist) {
                                    $artists[] = $artist['name'];
                                }
                                $this->SetValue('CurrentArtist', $currentPlay['item']['show']['publisher']);
                                $this->SetValue('CurrentAlbum', $currentPlay['item']['show']['name']);
                                if (isset($currentPlay['item']['show']['images'][0])) {
                                    $imageObject = $currentPlay['item']['show']['images'][0];
                                    $this->SetValue('CurrentImage', '<iframe style="border: 0;" height="' . $imageObject['height'] . '" width = "' . $imageObject['width'] . '" src="' . $imageObject['url'] . '">');
                                }
                                else {
                                    $this->SetValue('CurrentImage', '');
                                }
                                break;

                            default:
                                $resetCurrentPlaying();
                                break;

                        }
                    }
                    else {
                        $resetCurrentPlaying();
                    }
                } else {
                    $resetCurrentPlaying(true);
                }
            } else {
                $resetCurrentPlaying(true);
            }
        }

        public function ResetToken()
        {
            $this->WriteAttributeString('Token', '');
        }

        /**
         * This function will be called by the OAuth control. Visibility should be protected!
         */
        protected function ProcessOAuthData()
        {

            //Lets assume requests via GET are for code exchange. This might not fit your needs!
            if ($_SERVER['REQUEST_METHOD'] == 'GET') {
                if (!isset($_GET['code'])) {
                    die('Authorization Code expected');
                }

                $token = $this->FetchRefreshToken($_GET['code']);

                $this->SendDebug('ProcessOAuthData', "OK! Let's save the Refresh Token permanently", 0);

                $this->WriteAttributeString('Token', $token);

                $this->ReloadForm();
            } else {

                //Just print raw post data!
                echo file_get_contents('php://input');
            }
        }

        private function isFavorite($URI)
        {
            $favorites = json_decode($this->ReadAttributeString('Favorites'), true);
            return count(array_filter($favorites, function ($favorite) use ($URI)
            {
                return $favorite['uri'] === $URI;
            })) > 0;
        }

        private function getCurrentDeviceID()
        {
            $deviceIDs = json_decode($this->ReadAttributeString('DeviceIDs'), true);
            if (isset($deviceIDs[GetValue($this->GetIDForIdent('Device'))])) {
                return urlencode($deviceIDs[GetValue($this->GetIDForIdent('Device'))]);
            } else {
                return '';
            }
        }

        private function requestCurrentPlay()
        {
            return json_decode($this->MakeRequest('GET', 'https://api.spotify.com/v1/me/player'), true);
        }

        private function isPlaybackActive($currentPlay = false)
        {
            if ($currentPlay === false) {
                $currentPlay = $this->requestCurrentPlay();
            }
            return $currentPlay && $currentPlay['is_playing'];
        }

        private function RegisterOAuth($WebOAuth)
        {
            $ids = IPS_GetInstanceListByModuleID('{F99BF07D-CECA-438B-A497-E4B55F139D37}');
            if (count($ids) > 0) {
                $clientIDs = json_decode(IPS_GetProperty($ids[0], 'ClientIDs'), true);
                $found = false;
                foreach ($clientIDs as $index => $clientID) {
                    if ($clientID['ClientID'] == $WebOAuth) {
                        if ($clientID['TargetID'] == $this->InstanceID) {
                            return;
                        }
                        $clientIDs[$index]['TargetID'] = $this->InstanceID;
                        $found = true;
                    }
                }
                if (!$found) {
                    $clientIDs[] = ['ClientID' => $WebOAuth, 'TargetID' => $this->InstanceID];
                }
                IPS_SetProperty($ids[0], 'ClientIDs', json_encode($clientIDs));
                IPS_ApplyChanges($ids[0]);
            }
        }

        private function FetchRefreshToken($code)
        {
            $this->SendDebug('FetchRefreshToken', 'Use Authentication Code to get our precious Refresh Token!', 0);

            //Exchange our Authentication Code for a permanent Refresh Token and a temporary Access Token
            $options = [
                'http' => [
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query(['code' => $code])
                ]
            ];
            $context = stream_context_create($options);
            $result = file_get_contents('https://oauth.ipmagic.de/access_token/' . $this->oauthIdentifer, false, $context);

            $data = json_decode($result);

            if (!isset($data->token_type) || $data->token_type != 'Bearer') {
                throw new Exception('Bearer Token expected');
            }

            //Save temporary access token
            $this->FetchAccessToken($data->access_token, time() + $data->expires_in);

            //Return RefreshToken
            return $data->refresh_token;
        }

        private function FetchAccessToken($Token = '', $Expires = 0)
        {

            //Exchange our Refresh Token for a temporary Access Token
            if ($Token == '' && $Expires == 0) {

                //Check if we already have a valid Token in cache
                $data = $this->GetBuffer('AccessToken');
                if ($data != '') {
                    $data = json_decode($data);
                    if (time() < $data->Expires) {
                        $this->SendDebug('FetchAccessToken', 'OK! Access Token is valid until ' . date('d.m.y H:i:s', $data->Expires), 0);
                        return $data->Token;
                    }
                }

                $this->SendDebug('FetchAccessToken', 'Use Refresh Token to get new Access Token!', 0);

                //If we slipped here we need to fetch the access token
                $options = [
                    'http' => [
                        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'method'  => 'POST',
                        'content' => http_build_query(['refresh_token' => $this->ReadAttributeString('Token')])
                    ]
                ];
                $context = stream_context_create($options);
                $result = file_get_contents('https://oauth.ipmagic.de/access_token/' . $this->oauthIdentifer, false, $context);

                $data = json_decode($result);

                if (!isset($data->token_type) || $data->token_type != 'Bearer') {
                    throw new Exception('Bearer Token expected');
                }

                //Update parameters to properly cache it in the next step
                $Token = $data->access_token;
                $Expires = time() + $data->expires_in;

                //Update Refresh Token if we received one! (This is optional)
                if (isset($data->refresh_token)) {
                    $this->SendDebug('FetchAccessToken', "NEW! Let's save the updated Refresh Token permanently", 0);

                    $this->WriteAttributeString('Token', $data->refresh_token);
                }
            }

            $this->SendDebug('FetchAccessToken', 'CACHE! New Access Token is valid until ' . date('d.m.y H:i:s', $Expires), 0);

            //Save current Token
            $this->SetBuffer('AccessToken', json_encode(['Token' => $Token, 'Expires' => $Expires]));

            //Return current Token
            return $Token;
        }

        private function MakeRequest($method, $url, $body = '')
        {
            $header = 'Authorization: Bearer ' . $this->FetchAccessToken() . "\r\n" . 'Content-Type: application/json';
            if (in_array($method, ['POST', 'PUT'])) {
                $header .= "\r\n" . 'Content-Length: ' . strlen($body);
            }

            $opts = [
                'http'=> [
                    'method' => $method,
                    'header' => $header
                ]
            ];

            if ($body != '') {
                $opts['http']['content'] = $body;
            }

            $this->SendDebug('Request URL', $url, 0);
            $this->SendDebug('Request Options', json_encode($opts), 0);
            $context = stream_context_create($opts);

            $response = file_get_contents($url, false, $context);
            $this->SendDebug('Response', $response, 0);

            return $response;
        }

        private function UpdateFavoritesProfile()
        {
            $profileName = 'Spotify.Favorites.' . $this->InstanceID;
            $profile = IPS_GetVariableProfile($profileName);

            // Delete all current associations
            foreach ($profile['Associations'] as $association) {
                IPS_SetVariableProfileAssociation($profileName, $association['Value'], '', '', -1);
            }

            $favorites = json_decode($this->ReadAttributeString('Favorites'), true);

            $this->SendDebug('Favorites', json_encode($favorites), 0);

            foreach ($favorites as $index => $favorite) {
                // Get proper name, depending on type
                $name = '';
                switch ($favorite['type']) {
                    case 'Album':
                    case 'Playlist':
                        $name = $favorite['albumPlaylist'];
                        break;

                    case 'Artist':
                        $name = $favorite['artist'];
                        break;

                    case 'Track':
                        $name = $favorite['track'];
                        break;
                }
                IPS_SetVariableProfileAssociation($profileName, $index, $this->Translate($favorite['type']) . ': ' . $name, '', -1);
            }
        }

        private function UpdateDevices()
        {
            $profileName = 'Spotify.Devices';
            if (!IPS_VariableProfileExists($profileName)) {
                IPS_CreateVariableProfile($profileName, 1);
            }

            $profile = IPS_GetVariableProfile($profileName);
            // Delete all current associations
            foreach ($profile['Associations'] as $association) {
                IPS_SetVariableProfileAssociation($profileName, $association['Value'], '', '', -1);
            }

            $devices = json_decode($this->MakeRequest('GET', 'https://api.spotify.com/v1/me/player/devices'), true);
            $deviceIDs = [];

            foreach ($devices['devices'] as $index => $device) {
                IPS_SetVariableProfileAssociation($profileName, $index, $device['name'], '', -1);
                $deviceIDs[$index] = $device['id'];
                if ($device['is_active']) {
                    $this->SetValue('Device', $index);
                }
            }

            $this->WriteAttributeString('DeviceIDs', json_encode($deviceIDs));
            $this->RegisterVariableInteger('Device', $this->Translate('Device'), $profileName, 0);
            $this->EnableAction('Device');
        }

        private function GetTranslatedFavorites()
        {
            $favorites = json_decode($this->ReadAttributeString('Favorites'), true);
            foreach ($favorites as $favorite) {
                $favorite['type'] = $this->Translate($favorite['type']);
            }
            return $favorites;
        }

        private function AddToFavorites($Favorite)
        {
            if (!$this->isFavorite($Favorite['uri'])) {
                $favorites = json_decode($this->ReadAttributeString('Favorites'), true);
                $favorites[] = $Favorite;
                $this->WriteAttributeString('Favorites', json_encode($favorites));
                $this->UpdateFormField('Favorites', 'values', json_encode($this->GetTranslatedFavorites()));
                $this->UpdateFavoritesProfile();
            }
        }
    }