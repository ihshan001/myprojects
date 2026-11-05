import SwiftUI

struct FindDoctorView: View {

    @EnvironmentObject var session: SessionManagerV2
    @State private var doctorName: String = ""
    @State private var selectedCategories: Set<String> = []
    @State private var isCategoryModalPresented = false
    @State private var categorySearch: String = ""
    @State private var selectedDoctor: InfoCardData?
    @State private var selectedAvailabilityId: String?
    @State private var navigateToAppointment = false
    @State private var isAvailabilityModalPresented = false
    @State private var selectedAvailabilityDate: Date? = nil

    private func assignDoctorToAppointment(_ doctor: InfoCardData, date: Date? = nil) {
     
        session.activities.removeAll { $0.service == .appointment && $0.testName == nil }

        var activity = Activity(service: .appointment)
        activity.selectedDoctor = doctor
        activity.appointmentDate = date
        activity.isSelected = true
        activity.stage = .planning
        activity.patientName = "Patient"
        activity.patientAge = 0
        activity.patientGender = ""
        session.activities.append(activity)

        
        let recommendedTests = TestRecommendation.recommendedTests(forDoctorSpecialty: doctor.subheading)
        session.activities.removeAll { $0.service == .appointment && $0.testName != nil }
        for test in recommendedTests {
            var testActivity = Activity(service: .appointment)
            testActivity.selectedDoctor = doctor
            testActivity.appointmentDate = date
            testActivity.isSelected = false
            testActivity.queueStage = .unknown
            testActivity.labStep = test.type == .labTest ? test : nil
            testActivity.imagingStep = test.type == .imaging ? test : nil
            testActivity.testName = test.name
            session.activities.append(testActivity)
        }
    }

    private var allDoctors: [InfoCardData] {
        DoctorData.doctorGroups.flatMap { $0.doctors }
    }

    private var filteredDoctors: [InfoCardData] {
        var doctors = allDoctors
        if !selectedCategories.isEmpty {
            doctors = DoctorData.doctorGroups
                .filter { selectedCategories.contains($0.specialty) }
                .flatMap { $0.doctors }
        }
        if !doctorName.isEmpty {
            doctors = doctors.filter {
                $0.heading.lowercased().contains(doctorName.lowercased())
            }
        }
        return doctors
    }

    private var modalCategories: [CategoryItem] {
        if categorySearch.isEmpty {
            return DoctorCategoriesData.categories
        } else {
            return DoctorCategoriesData.categories.filter {
                $0.title.lowercased().contains(categorySearch.lowercased())
            }
        }
    }

    private var defaultGridCategories: [CategoryItem] {
        var selectedItems = DoctorCategoriesData.categories.filter { selectedCategories.contains($0.title) }
        let unselectedItems = DoctorCategoriesData.categories.filter { !selectedCategories.contains($0.title) }
        let remainingCount = max(0, 8 - selectedItems.count)
        selectedItems.append(contentsOf: unselectedItems.prefix(remainingCount))
        return Array(selectedItems.prefix(8))
    }

    var body: some View {
        NavigationStack {
            ZStack {
                ScrollView {
                    VStack(alignment: .leading, spacing: 20) {
                        Text("Find Available Doctors")
                            .font(.system(size: 20, weight: .bold))
                            .frame(maxWidth: .infinity, alignment: .center)

                        IconInputField(
                            iconName: "SearchIcon",
                            placeholder: "Find a Doctor",
                            value: $doctorName
                        )
                        .padding(.top, Spacing.section)

                        HStack {
                            Text("Category")
                                .font(.system(size: 18, weight: .bold))
                            Spacer()
                            Text("See all")
                                .foregroundColor(.gray)
                                .font(.system(size: 14))
                                .onTapGesture { isCategoryModalPresented = true }
                        }
                        .padding(.top, Spacing.section)

                        CategoryGrid(
                            items: defaultGridCategories,
                            selectedCategories: $selectedCategories
                        )
                        .padding(.top, 10)

                        HStack {
                            Text("Available Doctors")
                                .font(.system(size: 18, weight: .bold))
                            Spacer()
                            Text("\(filteredDoctors.count)")
                                .foregroundColor(.gray)
                                .font(.system(size: 14))
                        }
                        .padding(.top, Spacing.section)

                        VStack(spacing: 10) {
                            ForEach(filteredDoctors.indices, id: \.self) { index in
                                InfoCard(
                                    data: filteredDoctors[index],
                                    onPriceTap: {
                                        selectedDoctor = filteredDoctors[index]
                                        isAvailabilityModalPresented = true
                                    }
                                )
                            }
                        }
                        .padding(.top, 10)
                    }
                    .padding()
                }

                CustomModal(isPresented: $isCategoryModalPresented) {
                    VStack(spacing: 16) {
                        Text("Select Categories")
                            .font(.title3.bold())
                            .padding(.bottom, 10)
                        IconInputField(
                            iconName: "SearchIcon",
                            placeholder: "Search Categories",
                            value: $categorySearch
                        )
                        LazyVGrid(columns: Array(repeating: .init(.flexible()), count: 4), spacing: 16) {
                            ForEach(modalCategories) { category in
                                CategoryBoxGrid(
                                    item: category,
                                    isSelected: selectedCategories.contains(category.title)
                                ) {
                                    if selectedCategories.contains(category.title) {
                                        selectedCategories.remove(category.title)
                                    } else {
                                        selectedCategories.insert(category.title)
                                    }
                                }
                            }
                        }
                        .padding(.top, Spacing.section)
                    }
                }

                CustomModal(isPresented: $isAvailabilityModalPresented) {
                    VStack {
                        if let doctor = selectedDoctor {
                            DoctorAvailabilitySelector(
                                items: doctor.availableDates ?? [],
                                selectedDate: $selectedAvailabilityDate
                            )
                            .onChange(of: selectedAvailabilityDate) { newDate in
                                if let selectedDate = newDate, let doctor = selectedDoctor {
                                    assignDoctorToAppointment(doctor, date: selectedDate)
                                    navigateToAppointment = true
                                    isAvailabilityModalPresented = false
                                }
                            }
                        }
                    }
                }

                if let doctor = selectedDoctor {
                    NavigationLink(
                        destination: DoctorAppointmentStarterView(doctor: doctor),
                        isActive: $navigateToAppointment
                    ) {
                        EmptyView()
                    }
                }
            }
        }
        .onAppear {
            session.currentService = .appointment
        }
    }
}

#Preview {
    FindDoctorView()
}
