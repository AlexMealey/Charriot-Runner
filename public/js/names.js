/* Word-names for the heralds to cry — rolled with the dice button.     */
const NAME_A = [
  "Swift", "Grim", "Golden", "Iron", "Silent", "Wild", "Bold", "Fierce",
  "Proud", "Stormbound", "Ember", "Dusky", "Noble", "Cunning", "Valiant", "Shadow",
];
const NAME_B = [
  "Badger", "Quill", "Falcon", "Stag", "Raven", "Fox", "Boar", "Hound",
  "Wyvern", "Gryphon", "Hart", "Adder", "Otter", "Crow", "Wolf", "Mare",
];
function wordName() {
  return (
    NAME_A[Math.floor(Math.random() * NAME_A.length)] +
    " " +
    NAME_B[Math.floor(Math.random() * NAME_B.length)]
  );
}
