import { Suspense } from "react";
import { SocialCallback } from "./social-callback";

export default function SocialAuthPage() {
  return (
    <Suspense>
      <SocialCallback />
    </Suspense>
  );
}
