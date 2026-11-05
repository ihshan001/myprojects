//
//  LabList.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-06.
//

import SwiftUI

struct LabList: View {

    let specialties: [CategoryItem] = LabCategoriesData.categories
    @StateObject private var session = SessionManagerV2()

    let labTests: [LabCardData] = LabData.labTests

    @State private var selectedTests: Set<UUID> = []
    @State private var selectedCategory: String? = nil
    @State private var searchText = ""
    @State private var navigateToNext = false

    private var pageTitle: String {
        selectedCategory ?? "Your Clinic Queue"
    }

    private var filteredLabTests: [LabCardData] {
        if searchText.isEmpty {
            return labTests
        } else {
            return labTests.filter { $0.title.localizedCaseInsensitiveContains(searchText) }
        }
    }

    private var selectedLabCards: [LabCardData] {
        filteredLabTests.filter { selectedTests.contains($0.id) }
    }

 
    private var allSelectedLabCards: [LabCardData] {
        labTests.filter { selectedTests.contains($0.id) }
    }

    private var availableLabCards: [LabCardData] {
        filteredLabTests.filter { !selectedTests.contains($0.id) }
    }

    var body: some View {
        NavigationStack {
            ZStack(alignment: .bottom) {
                ScrollView {
                    VStack(alignment: .leading, spacing: 20) {
                        Text(pageTitle)
                            .font(.system(size: 20, weight: .bold))
                            .frame(maxWidth: .infinity, alignment: .center)
                            .padding(.horizontal)
                            .animation(.easeInOut(duration: 0.25), value: pageTitle)
                    }
                    VStack(alignment: .center) {
                        IconInputField(
                            iconName: "SearchIcon",
                            placeholder: "Find a Lab Test",
                            value: $searchText
                        )
                        .padding(.top, 10)
                        .padding(.horizontal, 10)
                    }

                    Text("Category")
                        .font(.app(.heading))
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(.top, 32)
                        .padding(.horizontal, 10)

                    CategoryGrid(
                        items: specialties,
                        selectedCategories: Binding(
                            get: {
                                selectedCategory.map { [$0] } ?? []
                            },
                            set: { newValue in
                                let current: Set<String> = selectedCategory.map { [$0] } ?? []
                                let added = newValue.subtracting(current)

                                withAnimation(.easeInOut(duration: 0.25)) {
                                    if let newlyTapped = added.first {
                                        selectedCategory = newlyTapped
                                    } else {

                                        selectedCategory = nil
                                    }
                                }
                            }
                        )
                    )
                    .padding(.top, 20)
                    if !selectedTests.isEmpty {
                        Text("Selected Tests")
                            .font(.app(.heading))
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .padding(.top, 32)
                            .padding(.horizontal, 10)

                        VStack(spacing: 12) {
                            ForEach(selectedLabCards) { test in
                                BloodTestCard(
                                    image: TestDataset.imageName(for: test.title),
                                    title: test.title,
                                    specialText: "Available Now",
                                    detailLine1: "\(test.label1)\(test.label1Text)",
                                    detailLine2: "\(test.label2)\(test.label2Text)",
                                    showExtraSection: false,
                                    fee: test.buttonText,
                                    onButtonTap: {
                                        print("Fee button tapped for: \(test.title)")
                                    },
                                    isCheckboxSelectable: true,
                                    initiallySelected: true,
                                    onSelectionChange: { isSelected in
                                        if !isSelected { toggleSelection(test.id) }
                                    }
                                )
                            }
                        }
                        .padding(.top, 20)
                    }

                    if !availableLabCards.isEmpty {
                        Text("Choose Your Lab Test")
                            .font(.app(.heading))
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .padding(.top, 32)
                            .padding(.horizontal, 10)

                        VStack(spacing: 12) {
                            ForEach(availableLabCards) { test in
                                BloodTestCard(
                                    image: TestDataset.imageName(for: test.title),
                                    title: test.title,
                                    specialText: "Available Now",
                                    detailLine1: "\(test.label1)\(test.label1Text)",
                                    detailLine2: "\(test.label2)\(test.label2Text)",
                                    showExtraSection: false,
                                    fee: test.buttonText,
                                    onButtonTap: {
                                        print("Fee button tapped for: \(test.title)")
                                    },
                                    isCheckboxSelectable: true,
                                    initiallySelected: false,
                                    onSelectionChange: { isSelected in
                                        if isSelected { toggleSelection(test.id) }
                                    }
                                )
                            }
                        }
                        .padding(.top, 20)
                        .onAppear {
                            session.currentService = .lab
                        }
                    }
                }
                .padding(.horizontal, 2)
                .padding(.bottom, 100)
                .animation(.spring(), value: selectedTests.isEmpty)

                VStack(spacing: 0) {
                    LinearGradient(
                        colors: [Color(.systemBackground).opacity(0), Color(.systemBackground)],
                        startPoint: .top,
                        endPoint: .bottom
                    )
                    .frame(height: 24)

                    HStack {
                        PrimaryButton(title: "Next", maxWidth: 320) {
                            navigateToNext = true
                        }
                        .disabled(selectedTests.isEmpty)
                        .opacity(selectedTests.isEmpty ? 0.5 : 1.0)
                    }
                    .frame(maxWidth: .infinity, alignment: .center)
                    .padding(.vertical, 16)
                    .background(Color(.systemBackground))
                    .shadow(color: Color.black.opacity(0.05), radius: 5, x: 0, y: -5)
                }
                .animation(.spring(), value: selectedTests.isEmpty)
            }
         
            .navigationDestination(isPresented: $navigateToNext) {
                LabTestDetailsView(selectedLabCards: allSelectedLabCards)
                    .environmentObject(session)
            }
        }
    }

    private func toggleSelection(_ id: UUID) {
        withAnimation(.spring()) {
            if selectedTests.contains(id) {
                selectedTests.remove(id)
            } else {
                selectedTests.insert(id)
            }
        }
    }
}

struct LabListView_Previews: PreviewProvider {
    static var previews: some View {
        LabList()
    }
}
