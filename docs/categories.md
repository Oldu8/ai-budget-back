# Categories (Level 1) and subcategories (Level 2)

Source: `database/data/categories.php` (seed). In the API, transactions and recurring expenses use **Level 2** `category_id` only.

**Visibility:** choosing which category groups to show or hide (`app_user_category_settings`, `PATCH /api/me/category-visibility`, visibility tags) is available on **all subscription tiers** including Free — there is no paid-only gate for this. See `docs/plans.md` and `.cursor/backend-spec.md` (categories & plans).

## Home

- Groceries (`home_groceries`)
- Snacks (`home_snacks`)
- Drinks (`home_drinks`)
- Household (`home_household`)
- Other (`home_other`)

## Kids

- Clothes (`kids_clothes`)
- Toys (`kids_toys`)
- Food & Formula (`kids_food`)
- Care & Hygiene (`kids_care`)
- Other (`kids_other`)

## Transport

- Taxi (`transport_taxi`)
- Public Transport (`transport_public`)
- Flights (`transport_flights`)
- Trains (`transport_trains`)
- Other (`transport_other`)

## Car

- Fuel (`car_fuel`)
- Parking (`car_parking`)
- Service (`car_service`)
- Car Wash (`car_wash`)
- Other (`car_other`)

## Housing

- Rent (`housing_rent`)
- Utilities (`housing_utilities`)
- Furniture (`housing_furniture`)
- Repairs (`housing_repairs`)
- Other (`housing_other`)

## Health

- Medicines (`health_medicines`)
- Treatment & Tests (`health_treatment_tests`)
- Dental (`health_dental`)
- Other (`health_other`)

## Connectivity

- Mobile Plan (`connectivity_mobile`)
- Home Internet (`connectivity_internet`)
- Top-ups (`connectivity_topups`)
- Other (`connectivity_other`)

## Subscriptions

- Streaming (`subscriptions_streaming`)
- Apps & Services (`subscriptions_apps`)
- Cloud Storage (`subscriptions_cloud`)
- Other (`subscriptions_other`)

## Fitness

- Gym (`fitness_gym`)
- Trainer (`fitness_trainer`)
- Sports (`fitness_sports`)
- Equipment (`fitness_equipment`)
- Other (`fitness_other`)

## Smoking

- Cigarettes (`smoking_cigarettes`)
- Vape (`smoking_vape`)
- Other (`smoking_other`)

## Pets

- Food (`pets_food`)
- Vet (`pets_vet`)
- Supplies (`pets_supplies`)
- Other (`pets_other`)

## Beauty

- Hygiene (`beauty_hygiene`)
- Cosmetics (`beauty_cosmetics`)
- Salon (`beauty_salon`)
- Skincare (`beauty_skincare`)
- Other (`beauty_other`)

## Leisure

- Cafes & Delivery (`leisure_food_delivery`)
- Entertainment (`leisure_entertainment`)
- Travel (`leisure_travel`)
- Hobbies (`leisure_hobbies`)
- Other (`leisure_other`)

## Shopping

- Clothes (`shopping_clothes`)
- Shoes (`shopping_shoes`)
- Electronics (`shopping_electronics`)
- Tools (`shopping_tools`)
- Other (`shopping_other`)

## Gifts

- Family (`gifts_family`)
- Friends (`gifts_friends`)
- Colleagues (`gifts_work`)
- Charity (`gifts_charity`)
- Other (`gifts_other`)

## Work

- Courses (`work_courses`)
- Expenses (`work_expenses`)
- Software (`work_software`)
- Equipment (`work_equipment`)
- Other (`work_other`)

## Finance

- Taxes (`finance_taxes`)
- Insurance (`finance_insurance`)
- Loans (`finance_loans`)
- Mortgage (`finance_mortgage`)
- Fees (`finance_fees`)
- Other (`finance_other`)
