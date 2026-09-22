# Checkpoint names and distance display

World Triathlon's results API documents the split headers Swim, T1, Bike, T2 and Run:
https://developers.triathlon.org/reference/program-results

IRONMAN's Knokke-Heist athlete guide labels the first transition route “SWIM EXIT TO BIKE START”:
https://www.ironman.com/sites/default/files/2024-11/im_70.3_knokke_heist_athlete_guide_english_2024.pdf

The app uses physical timing-point names so operators know exactly where to tap:

| Timing point | Meaning |
| --- | --- |
| Swim Exit | End of swim; entry to T1 |
| T1 (Bike Start) | Exit of swim-to-bike transition |
| Bike Finish | End of bike; entry to T2 |
| T2 (Run Start) | Exit of bike-to-run transition |
| Finish | End of run and overall race |

These are clear application labels based on established terminology, not a claim of federation certification. The result grid continues to show cumulative elapsed time and the split since the previous recorded checkpoint; a custom intermediate checkpoint does not represent a full sport split.

Distances show the distance into the current sport and cumulative course kilometres. For 1 km swim + 35 km bike + 8 km run the five totals are 1, 1, 36, 36, 44 km. T1 has 0 km of bike and T2 has 0 km of run. Totals use configured sport distances and exclude unmeasured travel within transition areas. Missing distances are shown as unavailable rather than guessed. Custom intermediate points use the same calculation.

A name-only migration updates exact previous built-in labels. Checkpoint IDs, codes, order, distances, custom names and timing records are preserved.
