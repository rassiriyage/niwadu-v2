import { FreshLink } from "../../fresh-link";
export default function StayNotFound() {
  return <section className="discovery-state"><h1>This stay is not available</h1><p>It may have been removed or the link may be incorrect.</p><FreshLink className="discovery-button" href="/hotels?sort=name">Browse stays</FreshLink></section>;
}
