/**
 * Basemaps OpenFreeMap serves, all keyless and unmetered.
 *
 * `auto` follows the app's own light/dark setting; anything else pins the map
 * regardless of the interface around it.
 */
export const MAP_STYLES = [
    { id: 'auto', label: 'Match interface', dark: null },
    { id: 'fiord', label: 'Fiord', dark: true },
    { id: 'dark', label: 'Dark', dark: true },
    { id: 'positron', label: 'Positron', dark: false },
    { id: 'liberty', label: 'Liberty', dark: false },
    { id: 'bright', label: 'Bright', dark: false },
] as const;

export type MapStyleId = (typeof MAP_STYLES)[number]['id'];

/** Resolve a preference plus the current interface theme to a style URL. */
export function styleUrlFor(preference: MapStyleId, isDark: boolean): string {
    const style =
        preference === 'auto' ? (isDark ? 'fiord' : 'positron') : preference;

    return `https://tiles.openfreemap.org/styles/${style}`;
}

/** One of many results, as FindPlaces hands them back. */
export type MapMarker = {
    lat: number;
    lon: number;
    name: string;
    /** Set when several searches share one map, so each pin keeps its symbol. */
    categoryKey?: string;
    /** Whatever OpenStreetMap knew that a visitor can act on. Keys from FindPlaces::DETAIL_TAGS. */
    details?: Partial<
        Record<
            | 'address'
            | 'hours'
            | 'website'
            | 'phone'
            | 'cuisine'
            | 'wheelchair'
            | 'outdoor_seating'
            | 'internet_access'
            | 'description',
            string
        >
    >;
};

/** What a map tool hands back, once parsed. */
export type MapView = {
    label: string;
    bbox: [string, string, string, string];
    /** A single located place, from ShowOnMap. */
    marker?: [string, string];
    /** Everything of one kind in an area, from FindPlaces. */
    markers?: MapMarker[];
    /** Stable FindPlaces category used to select a map symbol. */
    categoryKey?: string;
    /** What was searched for, already pluralised by the tool. */
    category?: string;
};

/** Where the map actually sits right now, which the visitor may have panned. */
export type MapViewport = {
    label: string;
    center: [number, number];
    zoom: number;
    moved: boolean;
};

/**
 * Identity for a view.
 *
 * The view is re-derived from the transcript on every streamed token, so the
 * object reference changes constantly while the place is nothing new. Comparing
 * this instead means the camera only moves when the place actually differs.
 */
export function viewKey(view: MapView): string {
    // The pins are part of the identity, not decoration. Two searches of the
    // same town share a bounding box and often a count, so without them
    // "cafes in Shibuya" searched twice would leave the first set on the map.
    const pins = (view.markers ?? [])
        .map((marker) => `${marker.name}@${marker.lat},${marker.lon}`)
        .join(';');

    return `${view.label}|${view.bbox.join(',')}|${pins}`;
}

/**
 * The tools whose results move the map.
 *
 * Mirrors `ChatController::MAP_TOOLS`. Streamed parts are named `tool-<name>`,
 * so these are the bare names and the `tool-` prefix is added where matched.
 */
export const MAP_TOOLS = ['show_on_map', 'find_places'] as const;

/**
 * Read a map view out of a tool result.
 *
 * The map tools answer in prose when they cannot place somewhere, so anything
 * that is not a well-formed view means "leave the map alone".
 */
export function toMapView(output: unknown): MapView | null {
    try {
        const parsed = JSON.parse(String(output));

        return Array.isArray(parsed?.bbox) && parsed.bbox.length === 4
            ? (parsed as MapView)
            : null;
    } catch {
        return null;
    }
}

/**
 * One view for everything a single reply put on the map.
 *
 * The assistant often searches twice in one turn ("restaurants" then
 * "museums") and finishes by placing the town itself. Taking the last result
 * left one pin where twenty belonged. Any searches in the reply win over a
 * plain placement; their pins are pooled, and the box grows to hold them all.
 */
export function mergeViews(views: MapView[]): MapView | null {
    const searches = views.filter((view) => view.markers?.length);

    if (searches.length === 0) {
        return views.at(-1) ?? null;
    }

    if (searches.length === 1) {
        return searches[0];
    }

    const markers = searches.flatMap((view) =>
        (view.markers ?? []).map((marker) => ({
            ...marker,
            categoryKey: marker.categoryKey ?? view.categoryKey,
        })),
    );

    const lons = searches.flatMap((view) => [+view.bbox[0], +view.bbox[2]]);
    const lats = searches.flatMap((view) => [+view.bbox[1], +view.bbox[3]]);

    const area = searches[0].label.split(' in ').slice(1).join(' in ');
    const categories = searches
        .map((view) => view.category ?? view.label)
        .join(' and ');

    return {
        label: area ? `${categories} in ${area}` : categories,
        category: categories,
        bbox: [
            String(Math.min(...lons)),
            String(Math.min(...lats)),
            String(Math.max(...lons)),
            String(Math.max(...lats)),
        ],
        markers,
    };
}
