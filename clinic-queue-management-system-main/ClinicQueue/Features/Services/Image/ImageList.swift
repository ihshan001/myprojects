//
//  ImageList.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-10.
//

import SwiftUI

struct ImageList: View {

    let specialties: [CategoryItem] = ImagingCategoriesData.categories
    let imagingTests: [ClinicStep] = ImagingData.imagingTests

    @EnvironmentObject var sessionManager: SessionManager
    @State private var selectedTests: Set<UUID> = []
    @State private var searchText = ""
    @State private var selectedCategory: String? = nil
    @State private var navigateToNext = false

    private var pageTitle: String {
        selectedCategory ?? "Imaging Services"
    }

    private var filteredImagingTests: [ClinicStep] {
        if searchText.isEmpty {
            return imagingTests
        } else {
            return imagingTests.filter { $0.name.localizedCaseInsensitiveContains(searchText) }
        }
    }

    private var selectedImagingCards: [ClinicStep] {
        filteredImagingTests.filter { selectedTests.contains($0.id) }
    }

    private var availableImagingCards: [ClinicStep] {
        filteredImagingTests.filter { !selectedTests.contains($0.id) }
    }

    var body: some View {
        NavigationStack {
            ZStack(alignment: .bottom) {
                ScrollView {
                    VStack(alignment: .leading, spacing: 20) {
                        HeaderSection(title: pageTitle)
                            .animation(.easeInOut(duration: 0.25), value: pageTitle)
                    }
                    .padding(.top, -60)

                    VStack(alignment: .center) {
                        IconInputField(
                            iconName: "SearchIcon",
                            placeholder: "Find an Imaging Test",
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
                            ForEach(selectedImagingCards) { test in
                                BloodTestCard(
                                    image: TestDataset.imageName(for: test.name),
                                    title: test.name,
                                    specialText: "Available Now",
                                    detailLine1: "Location: \(test.location)",
                                    detailLine2: "Wait: \(test.estimatedWait)",
                                    showExtraSection: false,
                                    fee: "$25",
                                    onButtonTap: {
                                        print("Fee button tapped for: \(test.name)")
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

              
                    if !availableImagingCards.isEmpty {
                        Text("Choose Your Imaging Test")
                            .font(.app(.heading))
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .padding(.top, 32)
                            .padding(.horizontal, 10)

                        VStack(spacing: 12) {
                            ForEach(availableImagingCards) { test in
                                BloodTestCard(
                                    image: TestDataset.imageName(for: test.name),
                                    title: test.name,
                                    specialText: "Available Now",
                                    detailLine1: "Location: \(test.location)",
                                    detailLine2: "Wait: \(test.estimatedWait)",
                                    showExtraSection: false,
                                    fee: "$25",
                                    onButtonTap: {
                                        print("Fee button tapped for: \(test.name)")
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
                    }
                }
                .padding(.horizontal, 2)
                .padding(.bottom, 100)
                .animation(.spring(), value: selectedTests.isEmpty)
                .navigationDestination(isPresented: $navigateToNext) {
                    ImagingDetailsView(selectedTests: selectedImagingCards)
                        .environmentObject(sessionManager)
                }

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
#Preview {
    NavigationStack {
        ImageList()
            .environmentObject(SessionManager()) 
    }
}
