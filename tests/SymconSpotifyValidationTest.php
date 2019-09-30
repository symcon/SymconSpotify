<?php

declare(strict_types=1);
include_once __DIR__ . '/stubs/Validator.php';
class SymconSpotifyValidationTest extends TestCaseSymconValidation
{
    public function testValidateSymconSpotify(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }
    public function testValidateSpotifyModule(): void
    {
        $this->validateModule(__DIR__ . '/../Spotify');
    }
}