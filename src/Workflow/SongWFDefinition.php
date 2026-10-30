<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Song;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;
use Survos\WorkflowExtrasBundle\Attribute\Place;
use Survos\WorkflowExtrasBundle\Attribute\Transition;

#[AsWorkflow(name: self::WORKFLOW_NAME, supports: [Song::class], description: 'A song: sync its lyrics')]
class SongWFDefinition
{
    public const WORKFLOW_NAME = 'SongWorkflow';

    #[Place(initial: true, description: 'Song registered')]
    public const PLACE_NEW = 'new';

    #[Place(description: 'Lyrics synced')]
    public const PLACE_LYRICS = 'lyrics';

    #[Transition(from: self::PLACE_NEW, to: self::PLACE_LYRICS, description: 'Sync the lyrics')]
    public const TRANSITION_SYNC_LYRICS = 'sync_lyrics';
}
