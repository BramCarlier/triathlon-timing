import { usePage } from '@inertiajs/vue3';
import type { PageProps } from '../types';
export function usePermissions() {
 const page=usePage<PageProps>();
 return (permission:string) => page.props.auth.user?.role==='admin' || (page.props.auth.user?.permissions??[]).includes(permission);
}
