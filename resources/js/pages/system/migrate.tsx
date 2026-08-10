import { Head, router, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

/**
 * Import from ProjectSend v1.
 *
 * The page never waits on the work. Creating a run queues a job and
 * returns; everything after that is this component polling the run row,
 * which is the same shape the host's zip downloads use. It matters more
 * here than there: even *checking* a v1 install reads every account and
 * file row, and a browser cannot sit through that on a large one.
 */

const POLL_INTERVAL_MS = 2000;

interface Finding {
    level: 'blocker' | 'acknowledge' | 'note';
    code: string;
    message: string;
    context: Record<string, unknown>;
}

interface Run {
    id: number;
    status: string;
    mode: string;
    phase: string | null;
    processed: number;
    total: number | null;
    options: Record<string, unknown>;
    report: { preflight?: Finding[] } & Record<string, unknown>;
    error: string | null;
    finished_at: string | null;
}

interface Props {
    run: Run | null;
    directModeAvailable: boolean;
    hostIsFresh: boolean;
    strategies: string[];
    phaseLabels: Record<string, string>;
}

export default function Migrate({ run: initialRun, directModeAvailable, hostIsFresh, strategies, phaseLabels }: Props) {
    const { t } = useTranslation();

    // Held in state because polling updates it between visits — but the
    // server is authoritative whenever it does send a fresh one, and
    // useState keeps its *initial* value across re-renders. Without the
    // effect below, submitting the form created a run, queued the job,
    // and left the screen showing the empty form as though nothing had
    // happened: the new prop arrived and was ignored.
    const [run, setRun] = useState<Run | null>(initialRun);

    useEffect(() => {
        setRun(initialRun);
    }, [initialRun]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Settings'), href: '/system/settings' },
        { title: t('Import from ProjectSend v1'), href: '/system/migrate' },
    ];

    const busy = run !== null && ['pending', 'checking', 'running'].includes(run.status);

    // A completed run means this install is no longer fresh, whatever
    // the prop from the last server render says — and offering the form
    // again under the report would be an invitation to do something the
    // server will refuse.
    const canStartAnother =
        hostIsFresh && ! busy && (run === null || ['failed', 'blocked'].includes(run.status));

    const poll = useCallback(async (id: number) => {
        const response = await fetch(`/system/migrate/runs/${id}`, {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            setRun(await response.json());
        }
    }, []);

    useEffect(() => {
        if (!busy || run === null) {
            return;
        }

        const timer = setInterval(() => void poll(run.id), POLL_INTERVAL_MS);

        return () => clearInterval(timer);
    }, [busy, poll, run]);

    const findings = run?.report?.preflight ?? [];
    const blockers = findings.filter((f) => f.level === 'blocker');
    const acknowledgements = findings.filter((f) => f.level === 'acknowledge');
    const notes = findings.filter((f) => f.level === 'note');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Import from ProjectSend v1')} />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title={t('Import from ProjectSend v1')}
                    description={t(
                        'Brings a ProjectSend v1 install — its accounts, files, sharing and history — into this one. Run it once, on a ProjectSend that has been set up and not yet used.',
                    )}
                />

                {!hostIsFresh && run === null && (
                    <Alert variant="destructive">
                        <AlertTitle>{t('This install already has content')}</AlertTitle>
                        <AlertDescription>
                            {t(
                                'The migration tool imports into a fresh ProjectSend only — one with nothing in it but the administrator account you set up. It has no way to merge a v1 install with accounts and files that already exist here.',
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {run !== null && <RunStatus run={run} />}

                {blockers.length > 0 && <FindingList title={t('Fix these in ProjectSend v1 first')} findings={blockers} variant="destructive" />}

                {acknowledgements.length > 0 && (
                    <FindingList title={t('These have no equivalent in v2 and will be skipped')} findings={acknowledgements} variant="default" />
                )}

                {notes.length > 0 && <FindingList title={t('What was found')} findings={notes} variant="default" />}

                {run?.status === 'needs_acknowledgement' && (
                    <div>
                        <Button onClick={() => router.post(`/system/migrate/runs/${run.id}/accept`, {}, { preserveScroll: true })}>
                            {t('I have read this — import anyway')}
                        </Button>
                    </div>
                )}

                {run?.status === 'completed' && <Report report={run.report} phaseLabels={phaseLabels} />}

                {canStartAnother && <SourceForm directModeAvailable={directModeAvailable} strategies={strategies} />}
            </div>
        </AppLayout>
    );
}

function RunStatus({ run }: { run: Run }) {
    const { t } = useTranslation();

    const labels: Record<string, string> = {
        pending: t('Queued'),
        checking: t('Checking the v1 install'),
        blocked: t('Cannot start'),
        needs_acknowledgement: t('Waiting for you'),
        running: t('Importing'),
        completed: t('Finished'),
        failed: t('Failed'),
    };

    const percent = run.total && run.total > 0 ? Math.min(100, Math.round((run.processed / run.total) * 100)) : null;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    {labels[run.status] ?? run.status}
                    {run.phase !== null && <Badge variant="secondary">{run.phase}</Badge>}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {run.status === 'running' && (
                    <>
                        <div className="bg-muted h-2 w-full overflow-hidden rounded">
                            <div className="bg-primary h-full transition-all" style={{ width: `${percent ?? 0}%` }} />
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {t('This keeps going if you close the page. A large install takes a while — the log and download history are the slow part.')}
                        </p>
                    </>
                )}

                {run.error !== null && <p className="text-destructive text-sm">{run.error}</p>}
            </CardContent>
        </Card>
    );
}

function FindingList({ title, findings, variant }: { title: string; findings: Finding[]; variant: 'default' | 'destructive' }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className={variant === 'destructive' ? 'text-destructive' : undefined}>{title}</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {findings.map((finding) => (
                    <div key={finding.code} className="flex flex-col gap-1">
                        <p className="text-sm">{finding.message}</p>
                        {Array.isArray(finding.context.sample) && (
                            <ul className="text-muted-foreground list-disc pl-5 text-xs">
                                {(finding.context.sample as unknown[]).slice(0, 10).map((sample, index) => (
                                    <li key={index}>{String(sample)}</li>
                                ))}
                            </ul>
                        )}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

/**
 * Turns a report key into something readable.
 *
 * The report is written by the phases as machine keys, which is right
 * for a JSON document and wrong for a screen: the first version of this
 * rendered `imported_private_despite_v1_public_flag` verbatim, and a
 * map of every setting that was not carried as a single line of JSON.
 */
function humanize(key: string): string {
    const words = key.replace(/_/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

function Report({ report, phaseLabels }: { report: Record<string, unknown>; phaseLabels: Record<string, string> }) {
    const { t } = useTranslation();

    // In the order the phases ran, so the report reads as an account of
    // what happened rather than as a dump of a JSON object's keys.
    const order = Object.keys(phaseLabels);
    const phases = Object.entries(report)
        .filter(([key]) => key !== 'preflight' && key !== 'baseline')
        .sort(([a], [b]) => (order.indexOf(a) + 1 || 999) - (order.indexOf(b) + 1 || 999));

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('What was imported')}</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <Alert>
                    <AlertTitle>{t('Tell your clients before they try to sign in')}</AlertTitle>
                    <AlertDescription>
                        {t('ProjectSend v1 signed in with a username. This one signs in with an email address. Passwords are unchanged.')}
                    </AlertDescription>
                </Alert>

                <dl className="flex flex-col gap-4">
                    {phases.map(([phase, values]) => (
                        <div key={phase} className="border-border flex flex-col gap-1 border-b pb-3 last:border-0">
                            <dt className="font-medium">{phaseLabels[phase] ?? humanize(phase)}</dt>
                            <dd className="text-muted-foreground flex flex-col gap-1 text-sm">
                                <PhaseDetail values={(values ?? {}) as Record<string, unknown>} />
                            </dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}

function PhaseDetail({ values }: { values: Record<string, unknown> }) {
    const { t } = useTranslation();

    return (
        <>
            {Object.entries(values).map(([key, value]) => {
                if (key === 'skipped') {
                    return Object.entries((value ?? {}) as Record<string, number>).map(([reason, count]) => (
                        <span key={reason}>
                            {t(':count skipped', { count })} — {reason}
                        </span>
                    ));
                }

                // A map rather than a tally — the settings phase's record
                // of what it could not carry, and why. Collapsed, because
                // it is 150 lines long on a real install and nobody needs
                // it open by default.
                if (value !== null && typeof value === 'object') {
                    const entries = Object.entries(value as Record<string, unknown>);

                    return (
                        <details key={key}>
                            <summary className="cursor-pointer">
                                {humanize(key)} ({entries.length})
                            </summary>
                            <ul className="mt-1 flex flex-col gap-0.5 pl-4">
                                {entries.map(([name, reason]) => (
                                    <li key={name}>
                                        <code className="text-xs">{name}</code> — {String(reason)}
                                    </li>
                                ))}
                            </ul>
                        </details>
                    );
                }

                return (
                    <span key={key}>
                        {humanize(key)}: {String(value)}
                    </span>
                );
            })}
        </>
    );
}

function SourceForm({ directModeAvailable, strategies }: { directModeAvailable: boolean; strategies: string[] }) {
    const { t } = useTranslation();
    const [mode, setMode] = useState<'direct' | 'bundle'>(directModeAvailable ? 'direct' : 'bundle');

    const form = useForm({
        mode: directModeAvailable ? 'direct' : 'bundle',
        install_path: '',
        db_host: '',
        db_port: '',
        db_name: '',
        db_user: '',
        db_password: '',
        prefix: '',
        bundle_path: '',
        files: 'copy',
        history: 'full',
    });

    const choose = (next: 'direct' | 'bundle') => {
        setMode(next);
        form.setData('mode', next);
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Where is the ProjectSend v1 install?')}</CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/system/migrate', { preserveScroll: true });
                    }}
                    className="flex flex-col gap-6"
                >
                    {directModeAvailable && (
                        <div className="flex gap-2">
                            <Button type="button" variant={mode === 'direct' ? 'default' : 'outline'} onClick={() => choose('direct')}>
                                {t('On this machine')}
                            </Button>
                            <Button type="button" variant={mode === 'bundle' ? 'default' : 'outline'} onClick={() => choose('bundle')}>
                                {t('Somewhere else')}
                            </Button>
                        </div>
                    )}

                    {mode === 'direct' ? (
                        <div className="flex flex-col gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="install_path">{t('Path to the v1 install')}</Label>
                                <Input
                                    id="install_path"
                                    value={form.data.install_path}
                                    onChange={(event) => form.setData('install_path', event.target.value)}
                                    placeholder="/var/www/projectsend"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t(
                                        'The database connection is read from that install’s includes/sys.config.php, so there is usually nothing else to fill in.',
                                    )}
                                </p>
                                <InputError message={form.errors.install_path} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="db_host">{t('Database host (optional)')}</Label>
                                    <Input id="db_host" value={form.data.db_host} onChange={(event) => form.setData('db_host', event.target.value)} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_name">{t('Database name (optional)')}</Label>
                                    <Input id="db_name" value={form.data.db_name} onChange={(event) => form.setData('db_name', event.target.value)} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_user">{t('Database user (optional)')}</Label>
                                    <Input id="db_user" value={form.data.db_user} onChange={(event) => form.setData('db_user', event.target.value)} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="db_password">{t('Database password (optional)')}</Label>
                                    <Input
                                        id="db_password"
                                        type="password"
                                        value={form.data.db_password}
                                        onChange={(event) => form.setData('db_password', event.target.value)}
                                    />
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <Label htmlFor="bundle_path">{t('Path to the exported bundle')}</Label>
                            <Input
                                id="bundle_path"
                                value={form.data.bundle_path}
                                onChange={(event) => form.setData('bundle_path', event.target.value)}
                                placeholder="/var/www/ps-export"
                            />
                            <p className="text-muted-foreground text-xs">
                                {t('Run the exporter on the v1 machine, then copy what it produces onto this server and give the path here.')}{' '}
                                <a className="underline" href="/system/migrate/exporter">
                                    {t('Download the exporter')}
                                </a>
                            </p>
                            <InputError message={form.errors.bundle_path} />
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="files">{t('File bytes')}</Label>
                            <select
                                id="files"
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                value={form.data.files}
                                onChange={(event) => form.setData('files', event.target.value)}
                            >
                                {strategies.map((strategy) => (
                                    <option key={strategy} value={strategy}>
                                        {strategy}
                                    </option>
                                ))}
                            </select>
                            <p className="text-muted-foreground text-xs">
                                {t(
                                    'copy is safe everywhere. hardlink is instant and uses no extra disk, but only when both installs are on one filesystem. move empties the v1 install. defer imports everything except the bytes.',
                                )}
                            </p>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="history">{t('Download and activity history')}</Label>
                            <select
                                id="history"
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                value={form.data.history}
                                onChange={(event) => form.setData('history', event.target.value)}
                            >
                                <option value="full">{t('Import all of it')}</option>
                                <option value="none">{t('Skip it')}</option>
                            </select>
                            <p className="text-muted-foreground text-xs">
                                {t('Download counts are worked out from this history, so skipping it leaves every file showing none.')}
                            </p>
                        </div>
                    </div>

                    <InputError message={form.errors.mode} />

                    <div>
                        <Button type="submit" disabled={form.processing}>
                            {t('Check the v1 install')}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}
