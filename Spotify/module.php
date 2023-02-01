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
            $this->RegisterPropertyInteger('CoverMaxWidth', 0);
            $this->RegisterPropertyInteger('CoverMaxHeight', 0);

            $this->RegisterAttributeString('Token', '');
            $this->RegisterAttributeString('Favorites', '[]');

            $profileNameFavorites = 'Spotify.Favorites.' . $this->InstanceID;

            // Before there were String associations, favorites was an Integer Profile, so we delete that in case we come from an outdated version
            if (IPS_VariableProfileExists($profileNameFavorites) && (IPS_GetVariableProfile($profileNameFavorites)['ProfileType'] !== VARIABLETYPE_STRING)) {
                IPS_DeleteVariableProfile($profileNameFavorites);
            }

            // Associations will be added later in ApplyChanges
            if (!IPS_VariableProfileExists($profileNameFavorites)) {
                IPS_CreateVariableProfile($profileNameFavorites, VARIABLETYPE_STRING);
            }

            $profileNameDevices = 'Spotify.Devices';

            // Before there were String associations, devices was an Integer Profile, so we delete that in case we come from an outdated version
            if (IPS_VariableProfileExists($profileNameDevices) && (IPS_GetVariableProfile($profileNameDevices)['ProfileType'] !== VARIABLETYPE_STRING)) {
                IPS_DeleteVariableProfile($profileNameDevices);
            }

            if (!IPS_VariableProfileExists($profileNameDevices)) {
                IPS_CreateVariableProfile($profileNameDevices, VARIABLETYPE_STRING);
            }

            $this->RegisterVariableString('Favorite', $this->Translate('Favorite'), $profileNameFavorites, 50);
            $this->EnableAction('Favorite');

            $this->RegisterVariableString('Device', $this->Translate('Device'), $profileNameDevices, 50);
            $this->EnableAction('Device');

            $this->RegisterVariableInteger('Action', $this->Translate('Action'), '~PlaybackPreviousNext', 40);
            $this->EnableAction('Action');

            $this->RegisterVariableInteger('Volume', $this->Translate('Volume'), '~Volume', 45);
            $this->EnableAction('Volume');
            $this->RegisterVariableInteger('Repeat', $this->Translate('Repeat'), '~Repeat', 50);
            $this->EnableAction('Repeat');

            $this->RegisterVariableBoolean('Shuffle', $this->Translate('Shuffle'), '~Shuffle', 50);
            $this->EnableAction('Shuffle');

            $this->RegisterVariableString('CurrentTrack', $this->Translate('Current Track'), '~Song', 10);
            $this->RegisterVariableString('CurrentArtist', $this->Translate('Current Artist'), '~Artist', 20);
            $this->RegisterVariableString('CurrentAlbum', $this->Translate('Current Album'), '', 30);
            $this->RegisterVariableString('CurrentPosition', $this->Translate('Position'), '', 32);
            $this->RegisterVariableString('CurrentDuration', $this->Translate('Duration'), '', 34);
            $this->RegisterVariableFloat('CurrentProgress', $this->Translate('Progress'), '~Progress', 36);
            $this->EnableAction('CurrentProgress');
            $this->RegisterVariableString('CurrentPlaylist', $this->Translate('Playlist'), '~Playlist', 36);
            $this->EnableAction('CurrentPlaylist');

            if ((@$this->GetIDForIdent('Cover') === false)) {
                $coverID = IPS_CreateMedia(1);
                IPS_SetParent($coverID, $this->InstanceID);
                IPS_SetName($coverID, $this->Translate('Current Cover'));
                IPS_SetIdent($coverID, 'Cover');
                IPS_SetMediaFile($coverID, 'cover.' . $this->InstanceID . '.jpg', false);
                $this->SetBuffer('CoverURL', '');
                IPS_SetMediaContent($coverID, '');
            }

            $this->RegisterTimer('UpdateTimer', 0, 'SPO_UpdateVariables($_IPS["TARGET"]);');
            $this->RegisterTimer('UpdateProgressTimer', 0, 'SPO_UpdateProgress($_IPS["TARGET"]);');
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
                                    for ($i = 1; $i < count($form['actions']); $i++) {
                                        $form['actions'][$i]['visible'] = false;
                                    }
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
                    $this->PlayURI($Value);
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

                case 'Volume': {
                    $response = json_decode($this->MakeRequest('PUT', 'https://api.spotify.com/v1/me/player/volume?volume_percent=' . json_encode($Value), '', true), true);
                    if (isset($response['error']['reason'])) {
                        switch ($response['error']['reason']) {
                            case 'VOLUME_CONTROL_DISALLOW':
                                echo $this->Translate('The current device does not suppport setting the volume');
                                break;

                            default:
                                echo $response['error']['message'];
                                break;
                        }
                    } else {
                        $this->SetValue('Volume', $Value);
                    }
                    break;
                }

                case 'CurrentProgress':
                    $milliseconds = floor($Value * 0.01 * $this->durationToMs($this->GetValue('CurrentDuration')));
                    $this->MakeRequest('PUT', 'https://api.spotify.com/v1/me/player/seek?position_ms=' . json_encode($milliseconds));
                    $this->SetValue('CurrentProgress', $Value);
                    $this->SetValue('CurrentPosition', $this->msToDuration($milliseconds));
                    break;

                case 'CurrentPlaylist':
                    $list = json_decode($Value, true);
                    if (!is_array($list)) {
                        echo $this->Translate('Current value for playlist is invalid');
                        break;
                    }
                    switch ($this->GetBuffer('CurrentType')) {
                        case 'artist':
                            $this->SkipTracks($list['current']);
                            break;

                        case 'show':
                        case 'album':
                        case 'playlist':
                            // Special handling for tracks
                            $this->SendDebug('New Index', $list['current'], 0);
                            $this->SendDebug('New Entry', json_encode($list['entries'][$list['current']]), 0);
                            $body = [];
                            $body['context_uri'] = $this->GetBuffer('CurrentURI');
                            $body['offset'] = [
                                'uri' => $list['entries'][$list['current']]['uri']
                            ];

                            $this->MakeRequest('PUT', 'https://api.spotify.com/v1/me/player/play', json_encode($body));
                            break;

                    }
                    $this->UpdateVariables();
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
                $this->SkipTracks(1);
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

        public function AddSearchResultToFavorites(object $SearchResult)
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

        public function AddPlaylistToFavorites(object $Playlist)
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
            $this->SetStatus(102);
            $resetCurrentPlaying = function (bool $resetCommands = false)
            {
                $this->SendDebug('Reset', 'Current Playing', 0);
                $this->SetValue('CurrentTrack', self::PLACEHOLDER_NONE);
                $this->SetValue('CurrentArtist', self::PLACEHOLDER_NONE);
                $this->SetValue('CurrentAlbum', self::PLACEHOLDER_NONE);
                $this->SetValue('CurrentPosition', $this->msToDuration(0));
                $this->SetValue('CurrentProgress', 0);
                $this->SetValue('CurrentPlaylist', '');
                $this->SetValue('CurrentDuration', $this->msToDuration(0));
                $this->SetBuffer('CoverURL', '');
                IPS_SetMediaContent($this->GetIDForIdent('Cover'), '');
                $this->SetTimerInterval('UpdateProgressTimer', 0);
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
                if ($currentPlay !== false) {
                    $this->SetValue('Action', $this->isPlaybackActive($currentPlay) ? self::PLAY : self::PAUSE);
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

                    $this->SetValue('Volume', $currentPlay['device']['volume_percent']);

                    $this->SetValue('CurrentPosition', $this->msToDuration($currentPlay['progress_ms']));

                    $this->SetTimerInterval('UpdateProgressTimer', $this->isPlaybackActive($currentPlay) ? 1000 : 0);

                    if (isset($currentPlay['item']['type'])) {
                        $this->SetValue('CurrentDuration', $this->msToDuration($currentPlay['item']['duration_ms']));
                        $this->SetValue('CurrentProgress', $currentPlay['progress_ms'] / $this->durationToMs($this->GetValue('CurrentDuration')) * 100);

                        $getArtist = function ($item)
                        {
                            $this->SendDebug('Get Artist - Item', json_encode($item), 0);
                            switch ($item['type']) {
                                case 'track':
                                    $artists = [];
                                    foreach ($item['artists'] as $artist) {
                                        $artists[] = $artist['name'];
                                    }
                                    return implode(', ', $artists);

                                case 'episode':
                                    return $item['show']['publisher'];

                                default:
                                    return false;
                            }
                        };

                        $loadData = function ($name, $albumName, $images, $artist) use ($currentPlay, $getArtist)
                        {
                            $this->SetValue('CurrentTrack', $name);
                            $this->SetValue('CurrentArtist', $artist);
                            $this->SetValue('CurrentAlbum', $albumName);
                            $coverFound = false;
                            if (isset($images)) {
                                foreach ($images as &$imageObject) {
                                    if ((($imageObject['height'] <= $this->ReadPropertyInteger('CoverMaxHeight')) || ($this->ReadPropertyInteger('CoverMaxHeight') == 0)) &&
                                    (($imageObject['width'] <= $this->ReadPropertyInteger('CoverMaxWidth')) || ($this->ReadPropertyInteger('CoverMaxWidth') == 0))) {
                                        $coverFound = true;
                                        if ($this->GetBuffer('CoverURL') != $imageObject['url']) {
                                            $this->SetBuffer('CoverURL', $imageObject['url']);
                                            IPS_SetMediaContent($this->GetIDForIdent('Cover'), base64_encode(file_get_contents($imageObject['url'])));
                                        }
                                        break;
                                    }
                                }
                            }
                            if (!$coverFound) {
                                $this->SetBuffer('CoverURL', '');
                                IPS_SetMediaContent($this->GetIDForIdent('Cover'), '');
                            }

                            $resetPlaylist = true;
                            if (isset($currentPlay['context'])) {
                                $contextInfo = $this->MakeRequest('GET', $currentPlay['context']['href'], '', true);
                                if (is_string($contextInfo)) {
                                    $contextInfo = json_decode($contextInfo, true);
                                    if (!isset($contextInfo['error'])) {
                                        $playlistEntries = [];
                                        $currentIndex = -1;
                                        switch ($contextInfo['type']) {
                                            case 'playlist':
                                            case 'album':
                                            case 'show':
                                                $trackList = ($contextInfo['type'] === 'show') ?
                                                            $contextInfo['episodes']['items'] :
                                                            $contextInfo['tracks']['items'];
                                                foreach ($trackList as $index => &$track) {
                                                    if ($contextInfo['type'] === 'playlist') {
                                                        $track = $track['track'];
                                                    }
                                                    $playlistEntries[] = [
                                                        'artist'   => ($contextInfo['type'] === 'show') ? $contextInfo['publisher'] : $getArtist($track),
                                                        'song'     => $track['name'],
                                                        'duration' => floor($track['duration_ms'] / 1000),
                                                        'uri'      => $track['uri']
                                                    ];
                                                    if ($track['id'] === $currentPlay['item']['id']) {
                                                        $currentIndex = $index;
                                                    }
                                                }
                                                break;

                                            case 'artist':
                                                // An artist provides no playlist, so we get the queue instead
                                                $queueInfo = $this->MakeRequest('GET', 'https://api.spotify.com/v1/me/player/queue', '', true);
                                                if (is_string($queueInfo)) {
                                                    $queueInfo = json_decode($queueInfo, true);
                                                    if (!isset($queueInfo['error'])) {
                                                        $currentIndex = 0;
                                                        $queue = $queueInfo['queue'];
                                                        // Add the currently playing element as it is not included in the queue itself
                                                        array_unshift($queue, $queueInfo['currently_playing']);
                                                        foreach ($queue as $index => &$track) {
                                                            $playlistEntries[] = [
                                                                'artist'   => $getArtist($track),
                                                                'song'     => $track['name'],
                                                                'duration' => floor($track['duration_ms'] / 1000),
                                                                'uri'      => $track['uri']
                                                            ];
                                                        }
                                                    }
                                                }
                                                break;

                                        }
                                        if (count($playlistEntries) > 0) {
                                            $this->SetBuffer('CurrentType', $contextInfo['type']);
                                            $this->SetBuffer('CurrentURI', $contextInfo['uri']);
                                            $this->SetValue('CurrentPlaylist', json_encode([
                                                'current' => $currentIndex,
                                                'entries' => $playlistEntries
                                            ]));
                                            $resetPlaylist = false;
                                        }
                                    }
                                }
                            }
                            if ($resetPlaylist) {
                                $this->SetValue('CurrentPlaylist', '');
                            }
                        };

                        switch ($currentPlay['item']['type']) {
                            case 'track':
                                $artists = [];
                                foreach ($currentPlay['item']['artists'] as $artist) {
                                    $artists[] = $artist['name'];
                                }
                                $loadData($currentPlay['item']['name'], $currentPlay['item']['album']['name'], $currentPlay['item']['album']['images'], $getArtist($currentPlay['item']));
                                break;

                            case 'episode':
                                $loadData($currentPlay['item']['name'], $currentPlay['item']['show']['name'], $currentPlay['item']['images'], $getArtist($currentPlay['item']));
                                break;

                            default:
                                $resetCurrentPlaying();
                                break;

                        }
                    } else {
                        $resetCurrentPlaying();
                    }
                } else {
                    $resetCurrentPlaying(true);
                }
            } else {
                $this->SetStatus(104);
                $resetCurrentPlaying(true);
            }
        }

        public function UpdateProgress()
        {
            $currentDuration = $this->durationToMs($this->GetValue('CurrentDuration'));
            $newPosition = min($currentDuration, $this->durationToMs($this->GetValue('CurrentPosition')) + 1000);
            $this->SetValue('CurrentProgress', ($newPosition / $currentDuration) * 100);
            $this->SetValue('CurrentPosition', $this->msToDuration($newPosition));
            // If song should be finished, update variables
            if ($newPosition == $currentDuration) {
                $this->UpdateVariables();
            }
        }

        public function ResetToken()
        {
            $this->WriteAttributeString('Token', '');
        }

        public function MakeAPIRequest(string $Method, string $Url, string $Body)
        {
            if (substr($Url, 0, 1) !== '/') {
                $Url = '/' . $Url;
            }
            return $this->MakeRequest($Method, 'https://api.spotify.com/v1' . $Url, $Body);
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
                $this->SetStatus(102);
                $this->ReloadForm();
            } else {

                //Just print raw post data!
                echo file_get_contents('php://input');
            }
        }

        private function msToDuration($ms)
        {
            $minutes = floor($ms / 60 / 1000);
            $seconds = floor($ms / 1000) - ($minutes * 60);
            return "$minutes:" . str_pad(strval($seconds), 2, '0', STR_PAD_LEFT);
        }

        private function durationToMs($duration)
        {
            $split = explode(':', $duration);
            $result = 0;
            while (count($split) > 0) {
                $result *= 60;
                $result += intval(array_shift($split));
            }
            return $result * 1000;
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
            return $this->GetValue('Device');
        }

        private function requestCurrentPlay()
        {
            // The response could be false, which cannot be JSON decoded
            // additional_types=episode is required in case the user hears a podcast, otherwise it is irrelevant
            $response = $this->MakeRequest('GET', 'https://api.spotify.com/v1/me/player?additional_types=episode', '', true);
            if (is_string($response)) {
                $response = json_decode($response, true);
            }
            if (!is_array($response)) {
                return false;
            } elseif (isset($response['error'])) {
                return false;
            } else {
                return $response;
            }
        }

        // Default value is true, as that is not a possible return value of requrestCurrentPlay
        // false is possible if the previously requested play was erroneous. If the callback was flaky,
        // the current play would be requested again and could confirm that playback is active, even if
        // the provided parameter, e.g., during update, could be false
        private function isPlaybackActive($currentPlay = true)
        {
            if ($currentPlay === true) {
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
                        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'method'        => 'POST',
                        'content'       => http_build_query(['refresh_token' => $this->ReadAttributeString('Token')]),
                        'ignore_errors' => true
                    ]
                ];
                $context = stream_context_create($options);
                $result = file_get_contents('https://oauth.ipmagic.de/access_token/' . $this->oauthIdentifer, false, $context);

                $data = json_decode($result);

                if (!isset($data->token_type) || $data->token_type != 'Bearer') {
                    $this->SetStatus(104);
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

        private function MakeRequest($method, $url, $body = '', $ignoreErrors = false)
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

            if ($ignoreErrors) {
                $opts['http']['ignore_errors'] = true;
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
                IPS_SetVariableProfileAssociation($profileName, $favorite['uri'], $this->Translate($favorite['type']) . ': ' . $name, '', -1);
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

            foreach ($devices['devices'] as $device) {
                IPS_SetVariableProfileAssociation($profileName, $device['id'], $device['name'], '', -1);
                if ($device['is_active']) {
                    $this->SetValue('Device', $device['id']);
                }
            }
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

        private function SkipTracks($number)
        {
            for ($i = 0; $i < $number; $i++) {
                $this->MakeRequest('POST', 'https://api.spotify.com/v1/me/player/next');
            }
        }
    }
