import type { Service } from '@/components/machines/serviceFormat';

export type BuildingService = {
    jobId: string;
    name: string;
    state: 'queued' | 'building' | 'starting' | 'failed';
    phase: string | null;
    progress: number | null;
};

export type DeployJob = {
    id: string;
    kind?: string;
    service: string | null;
    state: string;
    phase: string | null;
    progress: number | null;
    createdAt?: string | null;
};

// Deploys whose service isn't in the last scan yet: queued, building,
// starting up, or failed in the last ten minutes. Jobs come newest first.
export function buildingServices(
    jobs: DeployJob[],
    services: Pick<Service, 'app' | 'details'>[],
    scannedAtSeconds: number | null | undefined,
): BuildingService[] {
    const inScan = new Set<string>();
    for (const service of services) {
        const project = service.details?.compose_project;
        if (project?.startsWith('serveros-')) {
            inScan.add(project.slice('serveros-'.length));
        }
        if (service.app) {
            inScan.add(service.app);
        }
    }
    const scannedAt = (scannedAtSeconds ?? 0) * 1000;
    const seen = new Set<string>();
    const rows: BuildingService[] = [];

    for (const job of jobs) {
        if (
            (job.kind ?? 'deploy') !== 'deploy' ||
            !job.service ||
            seen.has(job.service)
        ) {
            continue;
        }
        seen.add(job.service);
        const created = new Date(job.createdAt ?? 0).getTime();
        const moving = ['queued', 'accepted', 'running'].includes(job.state);
        const recent = Date.now() - created < 10 * 60_000;
        if (inScan.has(job.service) || (!moving && !recent)) {
            continue;
        }

        let state: BuildingService['state'];
        if (job.state === 'queued' || job.state === 'accepted') {
            state = 'queued';
        } else if (job.state === 'running') {
            state = 'building';
        } else if (job.state === 'succeeded') {
            if (scannedAt > created + 60_000) continue;
            state = 'starting';
        } else if (['failed', 'timed_out'].includes(job.state)) {
            state = 'failed';
        } else {
            continue;
        }

        rows.push({
            jobId: job.id,
            name: job.service,
            state,
            phase: job.phase,
            progress: job.progress,
        });
    }

    return rows;
}
