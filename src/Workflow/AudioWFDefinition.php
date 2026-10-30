<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Audio;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;
use Survos\WorkflowExtrasBundle\Attribute\Place;
use Survos\WorkflowMessengerBundle\Attribute\Transition;

#[AsWorkflow(name: self::WORKFLOW_NAME, supports: [Audio::class], description: 'An audio file: extract lyrics, then create midi and musicxml')]
class AudioWFDefinition
{
    public const WORKFLOW_NAME = 'AudioWorkflow';

    #[Place(initial: true, description: 'Audio file registered')]
    public const PLACE_NEW = 'new';

    #[Place(description: 'Lyrics extracted')]
    public const PLACE_LYRICS = 'lyrics';

    #[Place(description: 'Midi created')]
    public const PLACE_MIDI = 'midi';

    #[Place(description: 'MusicXML created')]
    public const PLACE_XML = 'xml';

    #[Transition(from: self::PLACE_NEW, to: self::PLACE_LYRICS, description: 'Extract the lyrics', async: true, transport: 'async')]
    #[Transition(from: self::PLACE_XML, to: self::PLACE_LYRICS, description: 'Extract the lyrics', async: true, transport: 'async')]
    #[Transition(from: self::PLACE_LYRICS, to: self::PLACE_LYRICS, description: 'Extract the lyrics again', async: true, transport: 'async')]
    public const TRANSITION_EXTRACT_LYRICS = 'extract_lyrics';

    #[Transition(from: self::PLACE_XML, to: self::PLACE_MIDI, description: 'Create the midi file', async: true, transport: 'async')]
    #[Transition(from: self::PLACE_LYRICS, to: self::PLACE_MIDI, description: 'Create the midi file', async: true, transport: 'async')]
    public const TRANSITION_CREATE_MIDI = 'create_midi';

    #[Transition(from: self::PLACE_MIDI, to: self::PLACE_XML, description: 'Create the MusicXML file', async: true, transport: 'async')]
    #[Transition(from: self::PLACE_XML, to: self::PLACE_XML, description: 'Create the MusicXML file again', async: true, transport: 'async')]
    public const TRANSITION_CREATE_XML = 'create_xml';
}
