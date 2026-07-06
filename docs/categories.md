# Categories (Level 1) and subcategories (Level 2)

Source: `database/data/categories.php` (seed). In the API, transactions and recurring expenses use **Level 2** `category_id` only.

**Ordering:** categories have an `order` column (parents: global position, children: position within their parent). `GET /api/categories` sorts by it; the seed defines the defaults below. Planned: let users reorder categories themselves.

**Visibility:** choosing which category groups to show or hide (`app_user_category_settings`, `PATCH /api/me/category-visibility`, visibility tags) is available on **all subscription tiers** including Free — there is no paid-only gate for this. See `docs/plans.md` and `.cursor/backend-spec.md` (categories & plans).

## 1. Groceries

- Meat (`groceries_meat`)
- Fish & Seafood (`groceries_fish`)
- Vegetables (`groceries_vegetables`)
- Fruits (`groceries_fruits`)
- Dairy (`groceries_dairy`)
- Bread & Bakery (`groceries_bakery`)
- Pantry (`groceries_pantry`)
- Sweets & Confectionery (`groceries_sweets`)
- Drinks (`groceries_drinks`)
- Other (`groceries_other`)

## 2. Housing

- Rent (`housing_rent`, tag `rent`)
- Utilities (`housing_utilities`)
- Furniture (`housing_furniture`)
- Repairs (`housing_repairs`)
- Household Goods (`housing_household`)
- Other (`housing_other`)

## 3. Transport

- Taxi (`transport_taxi`)
- Public Transport (`transport_public`)
- Flights (`transport_flights`)
- Trains (`transport_trains`)
- Other (`transport_other`)

## 4. Car (tag `car`)

- Fuel (`car_fuel`)
- Parking (`car_parking`)
- Service (`car_service`)
- Car Wash (`car_wash`)
- Other (`car_other`)

## 5. Health

- Medicines (`health_medicines`)
- Treatment & Tests (`health_treatment_tests`)
- Dental (`health_dental`)
- Other (`health_other`)

## 6. Leisure

- Cafes & Delivery (`leisure_food_delivery`)
- Entertainment (`leisure_entertainment`)
- Travel (`leisure_travel`)
- Hobbies (`leisure_hobbies`)
- Sports & Fitness (`leisure_fitness`, tag `fitness`)
- Other (`leisure_other`)

## 7. Shopping

- Clothes (`shopping_clothes`)
- Shoes (`shopping_shoes`)
- Electronics (`shopping_electronics`)
- Tools (`shopping_tools`)
- Other (`shopping_other`)

## 8. Kids (tag `kids`)

- Clothes (`kids_clothes`)
- Toys (`kids_toys`)
- Food & Formula (`kids_food`)
- Care & Hygiene (`kids_care`)
- Other (`kids_other`)

## 9. Pets (tag `pets`)

- Food (`pets_food`)
- Vet (`pets_vet`)
- Supplies (`pets_supplies`)
- Other (`pets_other`)

## 10. Beauty (tag `beauty`)

- Cosmetics & Care (`beauty_cosmetics`)
- Salon (`beauty_salon`)
- Other (`beauty_other`)

## 11. Connectivity

- Mobile (`connectivity_mobile`)
- Home Internet (`connectivity_internet`)
- Other (`connectivity_other`)

## 12. Subscriptions

- Services (`subscriptions_services`)
- Other (`subscriptions_other`)

## 13. Work

- Education (`work_education`)
- Equipment (`work_equipment`)
- Other (`work_other`)

## 14. Gifts

- Loved Ones (`gifts_close`)
- Work (`gifts_work`)
- Other (`gifts_other`)

## 15. Smoking (tag `smoking`)

- Cigarettes (`smoking_cigarettes`)
- Vape (`smoking_vape`)
- Other (`smoking_other`)

## 16. Finance

- Taxes (`finance_taxes`)
- Insurance (`finance_insurance`, tag `insurance`)
- Loans (`finance_loans`, tag `loan`)
- Mortgage (`finance_mortgage`, tag `mortgage`)
- Fees (`finance_fees`)
- Charity (`finance_charity`)
- Other (`finance_other`)
