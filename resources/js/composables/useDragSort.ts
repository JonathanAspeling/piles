import { onBeforeUnmount, ref } from 'vue';

export interface DragRect {
    left: number;
    top: number;
    width: number;
    height: number;
}

/** Where things were when a dragged item was dropped on a target, for the swap animation. */
export interface DropContext {
    /** The dragged item as drawn at the moment of release (following the pointer). */
    draggedRect: DragRect;
    /** Where the dragged item rests in its row. */
    draggedRest: DragRect;
    /** The target as drawn at release (raised under the dragged item). */
    targetRect: DragRect;
    /** Where the target rests. */
    targetRest: DragRect;
}

/**
 * A row of draggable items. Items can be dragged to a new place in their own
 * row (when `onReorder` is set), and/or dropped on another zone's elements
 * (`targets` + `onDropOnTarget`).
 */
export interface DragZone {
    /** The items, in display order. */
    items: () => HTMLElement[];
    idOf: (element: HTMLElement) => number | null;
    canDrag: (id: number) => boolean;
    onReorder?: (orderedIds: number[]) => void;
    targets?: () => HTMLElement[];
    onDropOnTarget?: (draggedId: number, target: HTMLElement, context: DropContext) => void;
    /** Called once the pointer has moved far enough to count as a drag. */
    onDragStart?: (id: number) => void;
}

interface Slot {
    element: HTMLElement;
    rest: DragRect;
    /** The vertical offset the item's own classes give it at rest (a lifted card sits raised). */
    baseY: number;
}

interface DragSession {
    zone: DragZone;
    pointerId: number;
    startX: number;
    startY: number;
    element: HTMLElement;
    id: number;
    index: number;
    started: boolean;
    slots: Slot[];
    ids: number[];
    bounds: DragRect | null;
    targets: { element: HTMLElement; rest: DragRect }[];
    hoverIndex: number | null;
    hoverTarget: HTMLElement | null;
    tilt: number;
    lastX: number;
}

const DRAG_THRESHOLD_PX = 6;
const SHIFT_EASE = 'transform 220ms cubic-bezier(0.2, 0.8, 0.2, 1)';
const DROP_MS = 200;
const RETURN_MS = 320;
/** How far outside its row a dragged item can stray and still count as "in the row". */
const ROW_SLACK_X = 32;
const ROW_SLACK_Y = 48;

/**
 * Where an element sits in the layout, ignoring any transform on it: the
 * press-down shrink (`active:scale-95`) is mid-transition when a drag begins.
 * Transforms here scale about the centre, so the drawn centre (less any
 * vertical offset) is the layout centre.
 */
function layoutRectOf(element: HTMLElement, offsetY: number): DragRect {
    const point = centre(rectOf(element));
    const width = element.offsetWidth;
    const height = element.offsetHeight;

    return { left: point.x - width / 2, top: point.y - offsetY - height / 2, width, height };
}

function rectOf(element: Element): DragRect {
    const rect = element.getBoundingClientRect();

    return { left: rect.left, top: rect.top, width: rect.width, height: rect.height };
}

function offsetY(element: Element): number {
    const transform = getComputedStyle(element).transform;

    return transform && transform !== 'none' ? new DOMMatrixReadOnly(transform).m42 : 0;
}

function centre(rect: DragRect): { x: number; y: number } {
    return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
}

function contains(rect: DragRect, x: number, y: number, slackX = 0, slackY = 0): boolean {
    return x >= rect.left - slackX && x <= rect.left + rect.width + slackX && y >= rect.top - slackY && y <= rect.top + rect.height + slackY;
}

/** A rect the size of `size`, centred where `drawn` is. */
function centredOn(drawn: DragRect, size: DragRect): DragRect {
    const point = centre(drawn);

    return { left: point.x - size.width / 2, top: point.y - size.height / 2, width: size.width, height: size.height };
}

/** `order` with the item at `from` moved to `to`. */
function moved<T>(order: T[], from: number, to: number): T[] {
    const next = [...order];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);

    return next;
}

function vibrate(ms: number) {
    try {
        navigator.vibrate?.(ms);
    } catch {
        // Not supported, or blocked until the user has interacted: fine either way.
    }
}

/**
 * Pointer-driven drag and drop for the board: mouse, pen and touch alike.
 *
 * A press that doesn't move is left alone, so taps keep working. Once it moves
 * past a few pixels the item follows the pointer, the rest of its row slides
 * aside to show where it would land, and letting go either settles it into its
 * new place, drops it on a target, or (anywhere else) glides it home.
 *
 * Elements are moved with inline transforms rather than reactive state so a
 * drag costs no re-renders, and the order is only committed once the motion
 * has finished, so the DOM reorder lands exactly where the eye already is.
 */
export function useDragSort() {
    const isDragging = ref(false);
    const prefersReducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let session: DragSession | null = null;
    let settling = false;

    function start(zone: DragZone, event: PointerEvent) {
        if (session || settling || !event.isPrimary || (event.pointerType === 'mouse' && event.button !== 0)) {
            return;
        }
        const element = zone.items().find((item) => item.contains(event.target as Node));
        const id = element ? zone.idOf(element) : null;
        if (!element || id === null || !zone.canDrag(id)) {
            return;
        }

        session = {
            zone,
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            element,
            id,
            index: -1,
            started: false,
            slots: [],
            ids: [],
            bounds: null,
            targets: [],
            hoverIndex: null,
            hoverTarget: null,
            tilt: 0,
            lastX: event.clientX,
        };
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        window.addEventListener('pointercancel', onCancel);
    }

    function begin(active: DragSession) {
        const items = active.zone.items();
        active.slots = items.map((element) => {
            const baseY = offsetY(element);

            return { element, baseY, rest: layoutRectOf(element, baseY) };
        });
        active.ids = items.map((element) => active.zone.idOf(element) ?? -1);
        active.index = items.indexOf(active.element);
        active.targets = (active.zone.targets?.() ?? []).map((element) => ({ element, rest: layoutRectOf(element, offsetY(element)) }));

        if (active.zone.onReorder && active.slots.length) {
            const lefts = active.slots.map((slot) => slot.rest.left);
            const tops = active.slots.map((slot) => slot.rest.top);
            const rights = active.slots.map((slot) => slot.rest.left + slot.rest.width);
            const bottoms = active.slots.map((slot) => slot.rest.top + slot.rest.height);
            const left = Math.min(...lefts);
            const top = Math.min(...tops);
            active.bounds = { left, top, width: Math.max(...rights) - left, height: Math.max(...bottoms) - top };
        }

        for (const slot of active.slots) {
            slot.element.style.transition = slot.element === active.element ? 'none' : prefersReducedMotion ? 'none' : SHIFT_EASE;
        }
        active.element.style.zIndex = '50';
        active.element.style.boxShadow = '0 22px 40px rgba(0,0,0,0.28)';
        active.element.style.willChange = 'transform';
        document.body.style.cursor = 'grabbing';
        document.body.style.userSelect = 'none';

        active.started = true;
        isDragging.value = true;
        active.zone.onDragStart?.(active.id);
        vibrate(8);
    }

    function onMove(event: PointerEvent) {
        const active = session;
        if (!active || event.pointerId !== active.pointerId) {
            return;
        }
        const dx = event.clientX - active.startX;
        const dy = event.clientY - active.startY;
        if (!active.started) {
            if (Math.hypot(dx, dy) < DRAG_THRESHOLD_PX) {
                return;
            }
            begin(active);
        }
        event.preventDefault();

        // A slight lean into the direction of travel, easing back upright when still.
        const velocity = event.clientX - active.lastX;
        active.lastX = event.clientX;
        active.tilt = prefersReducedMotion ? 0 : active.tilt * 0.75 + Math.max(-8, Math.min(8, velocity * 0.9)) * 0.25;

        const own = active.slots[active.index];
        active.element.style.transform = `translate(${dx}px, ${dy + own.baseY}px) scale(1.06) rotate(${active.tilt.toFixed(2)}deg)`;

        const restCentre = centre(own.rest);
        const x = restCentre.x + dx;
        const y = restCentre.y + dy;

        // Over a drop target? It rises to meet the card, and the row settles back.
        const target = active.targets.find((candidate) => contains(candidate.rest, x, y, 8, 8))?.element ?? null;
        if (target !== active.hoverTarget) {
            if (active.hoverTarget) {
                styleTarget(active.hoverTarget, false);
            }
            if (target) {
                styleTarget(target, true);
                vibrate(6);
            }
            active.hoverTarget = target;
        }

        let hoverIndex: number | null = null;
        if (!target && active.bounds && contains(active.bounds, x, y, ROW_SLACK_X, ROW_SLACK_Y)) {
            let nearest = Infinity;
            active.slots.forEach((slot, index) => {
                const point = centre(slot.rest);
                const distance = Math.hypot(point.x - x, point.y - y);
                if (distance < nearest) {
                    nearest = distance;
                    hoverIndex = index;
                }
            });
        }
        if (hoverIndex !== active.hoverIndex) {
            if (hoverIndex !== null && active.hoverIndex !== null) {
                vibrate(4);
            }
            active.hoverIndex = hoverIndex;
            shiftSiblings(active);
        }
    }

    function styleTarget(element: HTMLElement, raised: boolean) {
        element.style.transition = prefersReducedMotion ? 'none' : 'transform 160ms ease-out, box-shadow 160ms ease-out';
        element.style.transform = raised ? 'scale(1.07)' : '';
        element.style.boxShadow = raised ? '0 0 0 3px #F2B42B, 0 0 30px 8px rgba(242,180,43,0.55)' : '';
    }

    /** Slide every other item to the slot it would have if the dragged one landed at the hover position. */
    function shiftSiblings(active: DragSession) {
        const order = moved(
            active.slots.map((_, index) => index),
            active.index,
            active.hoverIndex ?? active.index,
        );
        active.slots.forEach((slot, index) => {
            if (index === active.index) {
                return;
            }
            const destination = active.slots[order.indexOf(index)].rest;
            const shiftX = destination.left - slot.rest.left;
            const shiftY = destination.top - slot.rest.top + slot.baseY;
            slot.element.style.transform = shiftX || shiftY ? `translate(${shiftX}px, ${shiftY}px)` : '';
        });
    }

    function onUp(event: PointerEvent) {
        const active = session;
        if (!active || event.pointerId !== active.pointerId) {
            return;
        }
        detach();
        if (!active.started) {
            // A tap: leave it to the click handlers.
            session = null;
            return;
        }
        swallowNextClick();

        if (active.hoverTarget) {
            dropOnTarget(active, active.hoverTarget);
        } else if (active.hoverIndex !== null && active.hoverIndex !== active.index) {
            settleInto(active, active.hoverIndex);
        } else {
            returnHome(active);
        }
    }

    function onCancel(event: PointerEvent) {
        if (session && event.pointerId === session.pointerId) {
            cancel();
        }
    }

    /** Abandon a drag in progress (e.g. the game ended under it): everything glides home. */
    function cancel() {
        const active = session;
        if (!active) {
            return;
        }
        detach();
        if (active.started) {
            returnHome(active);
        } else {
            session = null;
        }
    }

    function dropOnTarget(active: DragSession, target: HTMLElement) {
        const own = active.slots[active.index];
        const restingTarget = active.targets.find((candidate) => candidate.element === target)!.rest;
        // Where each card is now, at its resting size: the drag's lean and lift
        // only inflate the bounding box, and the swap's cards fly at slot size.
        const context: DropContext = {
            draggedRect: centredOn(rectOf(active.element), own.rest),
            draggedRest: own.rest,
            targetRect: centredOn(rectOf(target), restingTarget),
            targetRest: restingTarget,
        };
        active.zone.onDropOnTarget?.(active.id, target, context);
        // The swap's flying copies take over from here, drawn exactly where these are now.
        resetStyles([...active.slots.map((slot) => slot.element), target]);
        vibrate(10);
        finish();
    }

    function settleInto(active: DragSession, hoverIndex: number) {
        const own = active.slots[active.index];
        const destination = active.slots[hoverIndex].rest;
        const orderedIds = moved(active.ids, active.index, hoverIndex);
        settling = true;

        active.element.style.transition = prefersReducedMotion ? 'none' : `transform ${DROP_MS}ms cubic-bezier(0.2, 0.8, 0.2, 1)`;
        active.element.style.transform = `translate(${destination.left - own.rest.left}px, ${destination.top - own.rest.top + own.baseY}px)`;

        setTimeout(
            () => {
                // Clear the transforms and commit the order in one task: Vue moves the
                // elements before the next paint, so they never visibly jump.
                resetStyles(active.slots.map((slot) => slot.element));
                active.zone.onReorder?.(orderedIds);
                vibrate(6);
                finish();
            },
            prefersReducedMotion ? 0 : DROP_MS,
        );
    }

    function returnHome(active: DragSession) {
        const own = active.slots[active.index];
        settling = true;
        if (active.hoverTarget) {
            styleTarget(active.hoverTarget, false);
        }
        // A little overshoot on the way back, so it reads as "nope" rather than a glitch.
        active.element.style.transition = prefersReducedMotion ? 'none' : `transform ${RETURN_MS}ms cubic-bezier(0.34, 1.4, 0.64, 1)`;
        active.element.style.transform = own.baseY ? `translate(0px, ${own.baseY}px)` : 'translate(0px, 0px)';
        active.hoverIndex = null;
        shiftSiblings(active);

        setTimeout(
            () => {
                resetStyles([...active.slots.map((slot) => slot.element), ...(active.hoverTarget ? [active.hoverTarget] : [])]);
                finish();
            },
            prefersReducedMotion ? 0 : RETURN_MS,
        );
    }

    /** Hand the elements back to their classes without animating the change. */
    function resetStyles(elements: HTMLElement[]) {
        for (const element of elements) {
            element.style.transition = 'none';
            element.style.transform = '';
            element.style.boxShadow = '';
            element.style.zIndex = '';
            element.style.willChange = '';
        }
        requestAnimationFrame(() =>
            requestAnimationFrame(() => {
                for (const element of elements) {
                    element.style.transition = '';
                }
            }),
        );
    }

    function finish() {
        session = null;
        settling = false;
        isDragging.value = false;
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
    }

    function detach() {
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);
        window.removeEventListener('pointercancel', onCancel);
    }

    /** The click that follows a drag's release isn't a tap. */
    function swallowNextClick() {
        const swallow = (event: MouseEvent) => {
            event.stopPropagation();
            event.preventDefault();
        };
        window.addEventListener('click', swallow, { capture: true, once: true });
        setTimeout(() => window.removeEventListener('click', swallow, { capture: true }), 0);
    }

    onBeforeUnmount(() => {
        detach();
        finish();
    });

    return { start, cancel, isDragging };
}
