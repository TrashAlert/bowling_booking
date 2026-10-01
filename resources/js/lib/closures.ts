import type { LaneClosureReason } from '@/types';

export const closureLabels: Record<LaneClosureReason, string> = {
    re_oil: 'Re-oil',
    maintenance: 'Maintenance',
    repair: 'Repair',
};

/**
 * Whether a lane closed for this reason reopens by itself when the job's
 * time is up. A repair lasts until staff reopen the lane.
 */
export function isShortClosure(reason: LaneClosureReason): boolean {
    return reason !== 'repair';
}
