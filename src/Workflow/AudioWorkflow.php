<?php

namespace App\Workflow;

use App\Entity\Audio;
use App\Workflow\AudioWFDefinition as WF;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Process\Process;

class AudioWorkflow
{
	public const WORKFLOW_NAME = 'AudioWorkflow';

	public function __construct(
		private EntityManagerInterface $entityManager,
		private LoggerInterface $logger,
		#[Autowire('%env(WHISPER_CLI)%')] private string $whisperCli,
		#[Autowire('%env(WHISPER_MODEL)%')] private string $whisperModel,
		#[Autowire('%env(WHISPER_OUTPUT_DIR)%')] private string $whisperOutputDir,
		#[Autowire('%env(BASIC_PITCH_BIN)%')] private string $basicPitchBin,
		#[Autowire('%env(DEMUCS_BIN)%')] private string $demucsBin,
		#[Autowire('%env(MUSICXML_PYTHON)%')] private string $musicXmlPython,
		#[Autowire('%env(MUSICXML_SCRIPT)%')] private string $musicXmlScript,
		#[Autowire('%kernel.project_dir%')] private string $projectDir,
	)
	{
	}


	public function getAudio(\Symfony\Component\Workflow\Event\Event $event): Audio
	{
		/** @var Audio */ return $event->getSubject();
	}


	#[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_EXTRACT_LYRICS)]
	public function onExtractLyrics(TransitionEvent $event): void
	{
		$audio = $this->getAudio($event);
		$audioPath = $this->resolvePath($audio->fileAsset->path ?? null);
		if (!$audioPath || !is_file($audioPath) || !is_readable($audioPath)) {
			return;
		}

		if (!$this->whisperCli || !is_file($this->whisperCli)) {
			return;
		}

		if (!$this->whisperModel || !is_file($this->whisperModel)) {
			return;
		}

		$outputDir = $this->resolveOutputDir();
		if (!is_dir($outputDir)) {
			if (!mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
				$this->logger->error('Failed to create whisper output dir', [
					'path' => $outputDir,
				]);
				return;
			}
		}
		if (!is_dir($outputDir) || !is_writable($outputDir)) {
			$this->logger->error('whisper output dir not writable', [
				'path' => $outputDir,
			]);
			return;
		}

		$baseName = sprintf('audio_%d', $audio->id ?? time());
		$outputBase = $outputDir . '/' . $baseName;

		$process = new Process([
			$this->whisperCli,
			'-m',
			$this->whisperModel,
			'-f',
			$audioPath,
			'-ng',
			'-oj',
			'-otxt',
			'-of',
			$outputBase,
		]);
		$process->setTimeout(null);
        $this->logger->info('whisper-cli command: ' . "\n" . $process->getCommandLine());
		$process->run();

		if (!$process->isSuccessful()) {
			$this->logger->error('whisper-cli failed', [
				'command' => $process->getCommandLine(),
				'error' => trim($process->getErrorOutput()),
				'exitCode' => $process->getExitCode(),
			]);
			return;
		}

		$lyricsPath = $outputBase . '.txt';
		if (is_file($lyricsPath)) {
			$audio->lyricsText = trim((string) file_get_contents($lyricsPath));
		}
		$lyricsJsonPath = $outputBase . '.json';
		if (is_file($lyricsJsonPath)) {
			$rawJson = file_get_contents($lyricsJsonPath);
			if ($rawJson !== false) {
				try {
					$decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
					if (is_array($decoded)) {
						$audio->lyricsJson = $decoded;
					}
				} catch (\JsonException $e) {
					$this->logger->warning('Failed to decode whisper json', [
						'path' => $lyricsJsonPath,
						'error' => $e->getMessage(),
					]);
				}
			}
		}
        $this->entityManager->flush();
	}


	#[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_CREATE_XML)]
	public function onCreateXml(TransitionEvent $event): void
	{
		$audio = $this->getAudio($event);
		$audioPath = $this->resolvePath($audio->fileAsset->path ?? null);
		if (!$audioPath || !is_file($audioPath) || !is_readable($audioPath)) {
			return;
		}

		$musicXmlPython = $this->resolveToolPath(
			$this->musicXmlPython,
			[$this->resolveVenvBin('python')]
		);
		if (!$musicXmlPython) {
			$this->logger->error('musicxml python not installed; run install-lyrics-tools');
			return;
		}

		$musicXmlScript = $this->resolveMusicXmlScript($this->musicXmlScript);
		if (!$musicXmlScript) {
			$this->logger->error('musicxml script missing; run install-lyrics-tools');
			return;
		}

		$outputDir = $this->resolveOutputDir();
		if (!is_dir($outputDir)) {
			if (!mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
				$this->logger->error('Failed to create whisper output dir', [
					'path' => $outputDir,
				]);
				return;
			}
		}
		if (!is_dir($outputDir) || !is_writable($outputDir)) {
			$this->logger->error('whisper output dir not writable', [
				'path' => $outputDir,
			]);
			return;
		}

		$baseName = sprintf('audio_%d', $audio->id ?? time());
		$whisperJson = $outputDir . '/' . $baseName . '.json';
		if (!is_file($whisperJson)) {
			return;
		}

		$midiPath = $this->resolveExpectedMidiPath($audioPath, $outputDir);
		if (!is_file($midiPath)) {
			$this->logger->error('midi file missing before musicxml build', [
				'expected' => $midiPath,
				'outputDir' => $outputDir,
			]);
			return;
		}

		$musicXmlPath = $outputDir . '/' . $baseName . '.musicxml';
		$title = $this->normalizeMetaValue($audio->song->title ?? null)
			?? $this->normalizeMetaValue($audio->title ?? null);
		if (!$title) {
			$filename = $audio->fileAsset->filename ?? null;
			if ($filename) {
				$title = $this->normalizeMetaValue(pathinfo($filename, PATHINFO_FILENAME));
			}
		}
		$creator = $this->normalizeMetaValue($audio->fileAsset->probedData['summary']['tags']['artist'] ?? null)
			?? $this->normalizeMetaValue($audio->fileAsset->probedData['summary']['tags']['composer'] ?? null)
			?? $this->normalizeMetaValue($audio->song->writers ?? null);
		$subtitle = $this->normalizeMetaValue($audio->song->school ?? null);
		if (!$subtitle) {
			$dirname = $audio->fileAsset->dirname ?? null;
			if ($dirname) {
				$subtitle = $this->normalizeMetaValue(basename($dirname));
			}
		}

		$musicXmlArgs = [
			$musicXmlPython,
			$musicXmlScript,
			'--midi',
			$midiPath,
			'--whisper-json',
			$whisperJson,
			'--output',
			$musicXmlPath,
			'--simplify',
			'--grid',
			'1.0',
			'--melody',
		];
		if ($title) {
			$musicXmlArgs[] = '--title';
			$musicXmlArgs[] = $title;
		}
		if ($creator) {
			$musicXmlArgs[] = '--creator';
			$musicXmlArgs[] = $creator;
		}
		if ($subtitle) {
			$musicXmlArgs[] = '--subtitle';
			$musicXmlArgs[] = $subtitle;
		}

		$musicXmlProcess = new Process($musicXmlArgs);
        $this->logger->info('command: ' . "\n" . $musicXmlProcess->getCommandLine());
		$musicXmlProcess->setTimeout(null);
		$musicXmlProcess->run();
		$musicXmlStdout = trim($musicXmlProcess->getOutput());
		$musicXmlStderr = trim($musicXmlProcess->getErrorOutput());
		if ($musicXmlStdout !== '') {
			$this->logger->info('musicxml output', ['stdout' => $musicXmlStdout]);
		}
		if ($musicXmlStderr !== '') {
			$this->logger->warning('musicxml stderr', ['stderr' => $musicXmlStderr]);
		}
		if (!$musicXmlProcess->isSuccessful()) {
			$this->logger->error('musicxml build failed', [
				'command' => $musicXmlProcess->getCommandLine(),
				'error' => $musicXmlStderr,
				'exitCode' => $musicXmlProcess->getExitCode(),
			]);
			return;
		}
		if (!is_file($musicXmlPath)) {
			$this->logger->error('musicxml file missing after build', [
				'path' => $musicXmlPath,
			]);
			return;
		}
		$musicXmlContent = file_get_contents($musicXmlPath);
		if ($musicXmlContent === false) {
			$this->logger->error('musicxml file unreadable after build', [
				'path' => $musicXmlPath,
			]);
			return;
		}
		if (
			str_contains($musicXmlContent, 'Music21 Fragment')
			|| str_contains($musicXmlContent, '<creator type="composer">Music21</creator>')
		) {
            $musicXmlContent = str_replace('Music21 Fragment', $audio->title, $musicXmlContent);
            $musicXmlContent = str_replace('Music21</creator>', 'Kid Pan Alley</creator>', $musicXmlContent);
            file_put_contents($musicXmlPath, $musicXmlContent);
			$this->logger->error('musicxml metadata fallback detected', [
				'path' => $musicXmlPath,
			]);
//			return;
		}
		$audio->musicXml = (string) $musicXmlContent;
		$this->logger->warning($musicXmlPath);
		$this->entityManager->flush();
	}

	#[AsGuardListener(WF::WORKFLOW_NAME, WF::TRANSITION_CREATE_XML)]
	public function onCreateXmlGuard(GuardEvent $event): void
	{
		$audio = $this->getAudio($event);
		$audioPath = $this->resolvePath($audio->fileAsset->path ?? null);
		if (!$audioPath || !is_file($audioPath)) {
			$event->setBlocked(true, 'Audio file missing');
			return;
		}
		$outputDir = $this->resolveOutputDir();
		$midiPath = $this->resolveExpectedMidiPath($audioPath, $outputDir);
		if (!$midiPath || !is_file($midiPath)) {
			$event->setBlocked(true, 'MIDI file missing; run create_midi');
		}
	}

	#[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_CREATE_MIDI)]
	public function onExtractMidi(TransitionEvent $event): void
	{
		$audio = $this->getAudio($event);
		$audioPath = $this->resolvePath($audio->fileAsset->path ?? null);
		if (!$audioPath || !is_file($audioPath) || !is_readable($audioPath)) {
			return;
		}

		$outputDir = $this->resolveOutputDir();
		if (!is_dir($outputDir)) {
			if (!mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
				$this->logger->error('Failed to create whisper output dir', [
					'path' => $outputDir,
				]);
				return;
			}
		}
		if (!is_dir($outputDir) || !is_writable($outputDir)) {
			$this->logger->error('whisper output dir not writable', [
				'path' => $outputDir,
			]);
			return;
		}

		$midiPath = $outputDir . '/' . pathinfo($audioPath, PATHINFO_FILENAME) . '.mid';
		$altMidiPath = $outputDir . '/' . pathinfo($audioPath, PATHINFO_FILENAME) . '_basic_pitch.mid';
		if (is_file($midiPath) || is_file($altMidiPath)) {
			return;
		}

		$basicPitchBin = $this->resolveToolPath(
			$this->basicPitchBin,
			[$this->resolveVenvBin('basic-pitch')]
		);
		if (!$basicPitchBin) {
			$this->logger->error('basic-pitch not installed; run install-lyrics-tools');
			return;
		}
		$demucsBin = $this->resolveToolPath(
			$this->demucsBin,
			[$this->resolveVenvBin('demucs')]
		);
		if (!$demucsBin) {
			$this->logger->error('demucs not installed; run install-lyrics-tools');
			return;
		}

		$sourcePath = $this->extractVocalStem($audioPath, $outputDir, $demucsBin);
		if (!$sourcePath) {
			$this->logger->error('vocal stem unavailable; run install-lyrics-tools', [
				'path' => $audioPath,
			]);
			return;
		}

		$basicPitchProcess = new Process([
			$basicPitchBin,
			$outputDir,
			$sourcePath,
		]);
		$this->logger->info('command: ' . "\n" . $basicPitchProcess->getCommandLine());
		$basicPitchProcess->setTimeout(null);
		$basicPitchProcess->run();
		$basicPitchStdout = trim($basicPitchProcess->getOutput());
		$basicPitchStderr = trim($basicPitchProcess->getErrorOutput());
		if ($basicPitchStdout !== '') {
			$this->logger->info('basic-pitch output', ['stdout' => $basicPitchStdout]);
		}
		if ($basicPitchStderr !== '') {
			$this->logger->warning('basic-pitch stderr', ['stderr' => $basicPitchStderr]);
		}
		if (!$basicPitchProcess->isSuccessful()) {
			$this->logger->error('basic-pitch failed', [
				'command' => $basicPitchProcess->getCommandLine(),
				'error' => $basicPitchStderr,
				'exitCode' => $basicPitchProcess->getExitCode(),
			]);
			return;
		}

		$expectedMidi = $this->resolveExpectedMidiPath($audioPath, $outputDir);
		if (!$expectedMidi) {
			return;
		}
		if (!is_file($expectedMidi)) {
			$generatedMidi = $this->findGeneratedMidi($outputDir, $sourcePath);
			if ($generatedMidi && $generatedMidi !== $expectedMidi) {
				if (!@rename($generatedMidi, $expectedMidi)) {
					$this->logger->warning('Failed to rename generated midi', [
						'from' => $generatedMidi,
						'to' => $expectedMidi,
					]);
				}
			}
		}
	}

	private function resolvePath(?string $path): ?string
	{
		if (!$path) {
			return null;
		}

		if (str_starts_with($path, '/')) {
			return $path;
		}

		$absolute = $this->projectDir . '/' . ltrim($path, '/');
		$resolved = realpath($absolute);
		return $resolved ?: $absolute;
	}

	private function resolveOutputDir(): string
	{
		$dir = $this->whisperOutputDir ?: $this->projectDir . '/var/whisper';
		if (!str_starts_with($dir, '/')) {
			$dir = $this->projectDir . '/' . ltrim($dir, '/');
		}
		return $dir;
	}

	private function resolveExpectedMidiPath(string $audioPath, string $outputDir): ?string
	{
		$base = pathinfo($audioPath, PATHINFO_FILENAME);
		if (!$base) {
			return null;
		}
		$primary = $outputDir . '/' . $base . '.mid';
		if (is_file($primary)) {
			return $primary;
		}
		return $outputDir . '/' . $base . '_basic_pitch.mid';
	}

	private function findGeneratedMidi(string $outputDir, string $sourcePath): ?string
	{
		$sourceBase = pathinfo($sourcePath, PATHINFO_FILENAME);
		if ($sourceBase) {
			$candidate = $outputDir . '/' . $sourceBase . '.mid';
			if (is_file($candidate)) {
				return $candidate;
			}
			$candidateAlt = $outputDir . '/' . $sourceBase . '_basic_pitch.mid';
			if (is_file($candidateAlt)) {
				return $candidateAlt;
			}
		}
		$matches = glob($outputDir . '/*.mid') ?: [];
		if ($matches === []) {
			return null;
		}
		usort($matches, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
		return $matches[0] ?? null;
	}

	private function resolveVenvBin(string $binary): ?string
	{
		$home = getenv('HOME');
		if (!$home) {
			return null;
		}
		$path = rtrim($home, '/') . '/.venvs/lyric-music/bin/' . $binary;
		return is_file($path) ? $path : null;
	}

	private function resolveToolPath(?string $configured, array $fallbacks = []): ?string
	{
		$configured = $this->normalizeMetaValue($configured);
		if ($configured && is_file($configured)) {
			return $configured;
		}
		foreach ($fallbacks as $candidate) {
			if ($candidate && is_file($candidate)) {
				return $candidate;
			}
		}
		return null;
	}

	private function resolveMusicXmlScript(?string $configured): ?string
	{
		$configured = $this->normalizeMetaValue($configured);
		if ($configured && is_file($configured)) {
			return $configured;
		}
		$default = $this->projectDir . '/scripts/create_musicxml.py';
		return is_file($default) ? $default : null;
	}

	private function normalizeMetaValue(mixed $value): ?string
	{
		if ($value === null) {
			return null;
		}
		if (is_array($value)) {
			$parts = [];
			foreach ($value as $item) {
				$item = trim((string) $item);
				if ($item !== '') {
					$parts[] = $item;
				}
			}
			if ($parts === []) {
				return null;
			}
			return implode(', ', $parts);
		}
		if (is_scalar($value)) {
			$value = trim((string) $value);
			return $value === '' ? null : $value;
		}
		return null;
	}

	private function extractVocalStem(string $audioPath, string $outputDir, string $demucsBin): ?string
	{
		$stemBase = pathinfo($audioPath, PATHINFO_FILENAME);
		if (!$stemBase) {
			return null;
		}
		$demucsOutputDir = $outputDir . '/demucs';
		$stemDir = $demucsOutputDir . '/htdemucs/' . $stemBase;
		$vocalsPath = $stemDir . '/vocals.wav';
		if (is_file($vocalsPath)) {
			return $vocalsPath;
		}
		if (!is_dir($demucsOutputDir)) {
			if (!mkdir($demucsOutputDir, 0777, true) && !is_dir($demucsOutputDir)) {
				$this->logger->error('Failed to create demucs output dir', [
					'path' => $demucsOutputDir,
				]);
				return null;
			}
		}

		$process = new Process([
			$demucsBin,
			'--two-stems=vocals',
			'-o',
			$demucsOutputDir,
			$audioPath,
		]);
		$this->logger->info('command: ' . "\n" . $process->getCommandLine());
		$process->setTimeout(null);
		$process->run();
		$stdout = trim($process->getOutput());
		$stderr = trim($process->getErrorOutput());
		if ($stdout !== '') {
			$this->logger->info('demucs output', ['stdout' => $stdout]);
		}
		if ($stderr !== '') {
			$this->logger->warning('demucs stderr', ['stderr' => $stderr]);
		}
		if (!$process->isSuccessful()) {
			$this->logger->error('demucs failed', [
				'command' => $process->getCommandLine(),
				'error' => $stderr,
				'exitCode' => $process->getExitCode(),
			]);
			return null;
		}
		if (!is_file($vocalsPath)) {
			$this->logger->warning('demucs produced no vocal stem', [
				'path' => $vocalsPath,
			]);
			return null;
		}

		return $vocalsPath;
	}
}
