import { lazy, type ComponentProps } from 'react';
import { LazyHost } from './Lazy';
import type AddressDrawerT from './AddressDrawer';
import type ProfileDrawerT from './ProfileDrawer';
import type SupportDrawerT from './SupportDrawer';
import type ReviewDrawerT from './ReviewDrawer';
import type FilterDrawerT from './FilterDrawer';

/**
 * Every secondary side window of the store, each in its own lazily loaded chunk: the code (and the dialog library behind
 * it) is fetched the first time a window opens, so none of it is in the first-paint bundle. See docs/qa/SIDE_WINDOWS_STORE.md.
 */
const AddressImpl = lazy(() => import('./AddressDrawer'));
const ProfileImpl = lazy(() => import('./ProfileDrawer'));
const SupportImpl = lazy(() => import('./SupportDrawer'));
const ReviewImpl = lazy(() => import('./ReviewDrawer'));
const FilterImpl = lazy(() => import('./FilterDrawer'));

export function AddressWindow(p: ComponentProps<typeof AddressDrawerT>) { return <LazyHost when={p.open}><AddressImpl {...p} /></LazyHost>; }
export function ProfileWindow(p: ComponentProps<typeof ProfileDrawerT>) { return <LazyHost when={p.open}><ProfileImpl {...p} /></LazyHost>; }
export function SupportWindow(p: ComponentProps<typeof SupportDrawerT>) { return <LazyHost when={p.open}><SupportImpl {...p} /></LazyHost>; }
export function ReviewWindow(p: ComponentProps<typeof ReviewDrawerT>) { return <LazyHost when={p.open}><ReviewImpl {...p} /></LazyHost>; }
export function FilterWindow(p: ComponentProps<typeof FilterDrawerT>) { return <LazyHost when={p.open}><FilterImpl {...p} /></LazyHost>; }
