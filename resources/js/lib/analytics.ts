// Zdieľané konštanty a formátovače pre analytické dashboardy

export const SEGMENT_LABELS: Record<string, string> = {
    b2c: 'Maloobchod (B2C)',
    b2b: 'Veľkoobchod (B2B)',
};

// farby konzistentné naprieč všetkými grafmi (modrá = B2C, oranžová = B2B)
export const SEGMENT_COLORS: Record<string, string> = {
    b2c: '#2563eb',
    b2b: '#f59e0b',
};

export function formatEur(value: number | null | undefined): string {
    if (value === null || value === undefined) return '–';
    return new Intl.NumberFormat('sk-SK', { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 }).format(value);
}

export function formatNumber(value: number | null | undefined, digits = 0): string {
    if (value === null || value === undefined) return '–';
    return new Intl.NumberFormat('sk-SK', { maximumFractionDigits: digits }).format(value);
}

export function formatPct(value: number | null | undefined): string {
    if (value === null || value === undefined) return '–';
    return `${new Intl.NumberFormat('sk-SK', { maximumFractionDigits: 1 }).format(value)} %`;
}

// export tabuľky do CSV (oddeľovač ; kvôli slovenskému Excelu)
export function downloadCsv(filename: string, rows: Record<string, unknown>[]): void {
    if (!rows.length) return;
    const headers = Object.keys(rows[0]);
    const escape = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lines = [headers.map(escape).join(';'), ...rows.map((r) => headers.map((h) => escape(r[h])).join(';'))];
    const blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename.endsWith('.csv') ? filename : `${filename}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

// spoločné nastavenia ApexCharts (toolbar s exportom PNG/SVG/CSV pre prácu)
export function baseChartOptions(extra: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        chart: {
            fontFamily: 'inherit',
            toolbar: { show: true, tools: { download: true, zoom: false, pan: false, reset: false, zoomin: false, zoomout: false } },
            animations: { enabled: false },
            ...((extra.chart as object) ?? {}),
        },
        dataLabels: { enabled: false },
        ...extra,
    };
}
