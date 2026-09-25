<?php

namespace KimaiPlugin\MileageBundle\Service;

/**
 * A track as computed by Dawarich (GET /api/v1/tracks/{id}): one journey between two longer recording gaps,
 * split into transportation-mode segments.
 */
final class DawarichTrack
{
    /**
     * @param int $distance metres
     * @param list<TransportSegment> $segments sorted by time; empty when Dawarich has not classified the track yet
     * @param list<GpsPoint> $path the track's line (without times)
     */
    public function __construct(
        public readonly int $id,
        public readonly int $start,
        public readonly int $end,
        public readonly int $distance,
        public readonly ?string $dominantMode,
        public readonly array $segments,
        public readonly array $path,
    ) {
    }

    public function overlaps(int $from, int $to): bool
    {
        return $this->start < $to && $this->end > $from;
    }

    /**
     * Segments of the track. A track without segments (not classified yet) is one segment with the dominant mode
     * ("unknown" if there is none), just like Dawarich stores it when the detection fails.
     *
     * @return list<TransportSegment>
     */
    public function getSegments(): array
    {
        if ($this->segments !== []) {
            return $this->segments;
        }

        return [new TransportSegment($this->start, $this->end, $this->dominantMode ?? 'unknown', $this->distance, $this->path)];
    }
}
