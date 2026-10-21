import type { Metadata } from "next";
import { RequireAuth } from "@/components/require-auth";
import { MyBookings } from "./my-bookings";

export const metadata: Metadata = { title: "My bookings" , robots: { index: false } };

export default function BookingsPage() {
  return (
    <RequireAuth>
      <MyBookings />
    </RequireAuth>
  );
}
