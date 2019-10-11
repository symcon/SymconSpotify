# Spotify
Das Spotify-Modul ermöglicht die Verknüpfung mit einem Spotify-Premium-Konto. Darüber kann die Wiedergabe über das Konto gesteuert werden. So ist es möglich, die eine Wiedergabe zu starten oder die aktuelle Wiedergabe zu ändern bzw. zu beenden. Auch Optionen wie Wiederholung oder Zufallswiedergabe können gesetzt werden.
Innerhalb des Moduls werden Favoriten gespeichert, welche dann komfortabel aus der Visualisierung abgerufen werden können.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Verknüpfung mit einem Spotify-Premium-Konto
* Wiedergabe abspielen oder pausieren
* Zu nächstem oder vorherigen Song wechseln
* Gerät für Wiedergabe auswählen oder wechseln
* Favoriten abspeichern und wieder aufrufen
* Wiederholung oder Zufallswiedergabe aktivieren

### 2. Voraussetzungen

- IP-Symcon ab Version 5.3
- Spotify-Premium-Konto

### 3. Software-Installation

* Über den Module Store das Modul Spotify installieren.
* Alternativ über das Module Control folgende URL hinzufügen:
`https://github.com/DrNiels/Spotify`  

### 4. Einrichten der Instanzen in IP-Symcon

- Unter "Instanz hinzufügen" ist das 'Spotify'-Modul unter dem Hersteller 'Spotify' aufgeführt.  

__Konfigurationsseite__:

Initial muss das Modul durch einen Klick auf den Button "Registrieren" mit einem Spotify-Premium-Konto verknüpft werden. Durch den Klick öffnet sich ein Anmeldungsdialog von Spotify. Nach Eingabe der Benutzerdaten muss die Verknüpfung bestätigt werden. Danach stehen alle Funktionen des Moduls zur Verfügung.


___Erweiterte Einstellungen___
Name                     | Beschreibung
------------------------ | ---------------------------------
Aktualisierungsintervall | In diesem Intervall werden die Werte der Statusvariablen mit der aktuellen Wiedergabe von Spotify abgeglichen. Dies beinhaltet das Gerät, Wiedergabe oder Pause, Zufallswiedergabe und Wiederholung

___Suche___
Name                     | Beschreibung
------------------------ | ---------------------------------
Suche                    | In diesem Feld wird ein Suchbegriff für eine Suche eingegeben
Suche nach Alben         | Gibt an ob die nächste Suche Alben beinhalten soll
Suche nach Künstlern     | Gibt an ob die nächste Suche Künstler beinhalten soll
Suche nach Playlists     | Gibt an ob die nächste Suche Playlists beinhalten soll
Suche nach Songs         | Gibt an ob die nächste Suche Songs beinhalten soll
Suche starten            | Startet eine Suche
Suchergebnisse           | Diese Liste beinhaltet die Ergebnisse der aktuellen Suche - Durch ein Aktivieren des Feldes "Favorit" kann ein Ergbnis den Favoriten hinzugefügt werden

Die Liste "Ihre Playlists" beinhaltet die Playlists des verknüpften Spotify-Kontos. Diese können über das Feld "Favorit" den Favoriten hinzugefügt werden. Die Liste "Favoriten" beinhaltet die eingestellten Favoriten. Durch ein Klick auf das Mülleimer-Symbol können Favoriten entfernt werden.

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

##### Statusvariablen

Name                 | Typ     | Beschreibung
-------------------- | ------- | ----------------
Aktion               | integer | Hierüber kann eine Wiedergabe gestartet oder pausiert werden. Außerdem ist es möglich auf den vorherigen oder nächsten Song zu wechseln
Gerät                | integer | Mit dieser Variable kann ein Wiedergabegerät ausgewählt werden. Ist aktuell eine Wiedergabe aktiv, so wird diese auf das neue Gerät gewechselt
Favorit              | integer | Diese Variable stellt die im Modul konfigurierten Favoriten dar. Durch die Auswahl eines Favoriten wird dieser auf dem aktuell gewählten Gerät abgespielt
Wiederholen          | integer | Über diese Variable kann die Wiederholung aktiviert werden - "Aus": Keine Wiederholung, "Kontext": Der aktuelle Kontext, also das Album, die Playlist, ... wird wiederholt, "Song": Der aktuelle Song wird wiederholt
Zufällige Wiedergabe | boolean | Aktiviert bzw. deaktiviert die zufällige Wiedergabe

##### Profile:

Name                          | Typ
----------------------------- | ------- 
Spotify.Favoriter.<InstanzID> | Integer
Spotify.Actions               | Integer
Spotify.Devices               | Integer
Spotify.Repeat                | Integer

### 6. WebFront

Über das WebFront oder in den mobilen Apps werden Statusvariablen angezeigt und können geschaltet werden.

### 7. PHP-Befehlsreferenz

`boolean SPO_Play(integer $InstanzID);`  
Existiert eine aktuelle, möglicherweise pausierte, Wiedergabe, so wird diese fortgesetzt. Ansonsten wird der aktuell ausgewählte Favorit auf dem aktuell ausgewähltem Gerät abgespielt.  
Die Funktion liefert keinerlei Rückgabewert.  
Beispiel:  
`SPO_Play(12345);`

`boolean SPO_Pause(integer $InstanzID);`  
Existiert eine aktuelle Wiedergabe, so wird diese pausiert.  
Die Funktion liefert keinerlei Rückgabewert.  
Beispiel:  
`SPO_Pause(12345);`

`boolean SPO_PreviousTrack(integer $InstanzID);`  
Existiert eine aktuelle Wiedergabe, so wird der vorherige Song abgespielt.  
Die Funktion liefert keinerlei Rückgabewert.  
Beispiel:  
`SPO_PreviousTrack(12345);`

`boolean SPO_NextTrack(integer $InstanzID);`  
Existiert eine aktuelle Wiedergabe, so wird der nächste Song abgespielt.  
Die Funktion liefert keinerlei Rückgabewert.  
Beispiel:  
`SPO_NextTrack(12345);`

`boolean SPO_PlayURI(integer $InstanzID, string $URI);`  
Spielt die Spotify-Resource mit der URI $URI auf dem aktuell ausgewähltem Gerät ab.  
Die Funktion liefert keinerlei Rückgabewert.  
Beispiel:  
`SPO_PlayURI(12345, 'spotify:artist:1Lw1vZvhgNZk7hVSvdY4OA');`

`boolean SPO_SetRepeat(integer $InstanzID, integer $Repeat);`  
Setzt die Wiederholung auf den Wert $Repeat.  

Wert | Bedeutung
---- | ---------
0    | Wiederholung deaktiviert
1    | Wiederholung des Kontext
2    | Wiederholung des Songs

Die Funktion liefert keinerlei Rückgabewert.  
Beispiel:  
`SPO_SetRepeat(12345, 2);`

`boolean SPO_SetShuffle(integer $InstanzID, boolean $Shuffle);`  
Ist $Shuffle true, so wird die zufällige Wiedergabe aktiviert, ansonsten deaktiviert.  
Die Funktion liefert keinerlei Rückgabewert.  
Beispiel:  
`SPO_SetShuffle(12345, true);`

`boolean SPO_ResetToken(integer $InstanzID);`  
Setzt die OAuth-Token und somit die Verknüpfung mit dem Spotify-Konto zurück.  
Die Funktion liefert keinerlei Rückgabewert.  
Beispiel:  
`SPO_ResetToken(12345);`