import { useMemo, useRef } from '@wordpress/element';

export function normalizeUniqueValues(values, parser) {
    if (!Array.isArray(values)) {
        return [];
    }

    const normalized = [];
    const seen = new Set();

    values.forEach((value) => {
        const parsed = parser(value);
        if (parsed == null || seen.has(parsed)) {
            return;
        }

        seen.add(parsed);
        normalized.push(parsed);
    });

    return normalized;
}

export function parsePositiveInt(value) {
    const parsed = Number.parseInt(value, 10);
    if (!Number.isFinite(parsed) || parsed < 1) {
        return null;
    }

    return parsed;
}

export function parseSlug(value) {
    const normalized = String(value || '').trim();
    return normalized === '' ? null : normalized;
}

export function useQueryPostManualOrder({
    candidateRecords,
    orderedValues,
    valueSelector,
    limit,
    onOrderChange,
}) {
    const draggedItemRef = useRef(null);

    const draggableRecords = useMemo(() => {
        const records = Array.isArray(candidateRecords) ? candidateRecords : [];
        const safeLimit = typeof limit === 'number' && limit > 0 ? limit : records.length;

        const recordByValue = new Map();
        records.forEach((record) => {
            recordByValue.set(valueSelector(record), record);
        });

        const orderedSet = new Set(orderedValues);
        const allRecords = [];

        orderedValues.forEach((value) => {
            const record = recordByValue.get(value);
            if (record && allRecords.length < safeLimit) {
                allRecords.push(record);
            }
        });

        records.forEach((record) => {
            const value = valueSelector(record);
            if (!orderedSet.has(value) && allRecords.length < safeLimit) {
                allRecords.push(record);
            }
        });

        return allRecords;
    }, [candidateRecords, orderedValues, valueSelector, limit]);

    const handleDragStart = (event, value) => {
        draggedItemRef.current = {
            value,
            fromIndex: draggableRecords.findIndex((record) => valueSelector(record) === value),
        };
        event.dataTransfer.effectAllowed = 'move';
    };

    const handleDragOver = (event) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    };

    const handleDrop = (event, toIndex) => {
        event.preventDefault();

        if (!draggedItemRef.current) {
            return;
        }

        const { value, fromIndex } = draggedItemRef.current;
        draggedItemRef.current = null;

        if (fromIndex === -1 || fromIndex === toIndex) {
            return;
        }

        const nextOrder = [...orderedValues];
        const currentIndex = nextOrder.indexOf(value);

        if (currentIndex !== -1) {
            const [entry] = nextOrder.splice(currentIndex, 1);
            nextOrder.splice(toIndex, 0, entry);
        } else {
            nextOrder.splice(toIndex, 0, value);
        }

        onOrderChange(nextOrder);
    };

    return {
        draggableRecords,
        handleDragStart,
        handleDragOver,
        handleDrop,
    };
}
