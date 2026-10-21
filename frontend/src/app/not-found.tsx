import { ButtonLink, Empty } from "@/components/ui";

export default function NotFound() {
  return (
    <div className="mx-auto w-full max-w-xl px-4 py-16">
      <Empty title="Page not found" action={<ButtonLink href="/">Back to the map</ButtonLink>}>
        The page you&apos;re looking for doesn&apos;t exist.
      </Empty>
    </div>
  );
}
